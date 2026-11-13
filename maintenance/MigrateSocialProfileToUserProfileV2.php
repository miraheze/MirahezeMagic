<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

use MediaWiki\Context\RequestContext;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionRegistry;
use RuntimeException;
use SocialProfileFileBackend;
use Telepedia\UserProfileV2\Avatar\UserProfileV2AvatarBackend;
use Telepedia\UserProfileV2\ProfileFields;
use Throwable;

class MigrateSocialProfileToUserProfileV2 extends Maintenance {

	private const AVATAR_EXTENSIONS = [ 'png', 'gif', 'jpg', 'jpeg' ];

	/** @inheritDoc */
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Migrate SocialProfile about text and avatars to UserProfileV2.' );
		$this->addOption( 'dry-run', 'Report changes without writing them.' );
		$this->requireExtension( 'UserProfileV2' );
	}

	/** @inheritDoc */
	public function execute(): void {
		$dryRun = $this->hasOption( 'dry-run' );
		if ( $dryRun ) {
			$this->output( "DRY RUN: no changes will be written.\n" );
		}

		$errors = $this->migrateAboutText( $dryRun );
		$errors += $this->migrateAvatars( $dryRun );
		if ( $errors ) {
			$this->fatalError( "Migration finished with {$errors} error(s)." );
		}
		$this->output( "Done.\n" );
	}

	private function migrateAboutText( bool $dryRun ): int {
		$dbr = $this->getReplicaDB();
		if ( !$dbr->tableExists( 'user_profile', __METHOD__ ) ) {
			$this->output( "No user_profile table; skipping about text.\n" );
			return 0;
		}

		$hasPrivacyTable = $dbr->tableExists( 'user_fields_privacy', __METHOD__ );

		$queryBuilder = $dbr->newSelectQueryBuilder()
			->select( [ 'up_about', 'actor_user' ] )
			->from( 'user_profile' )
			->join( 'actor', null, [ 'actor.actor_id = user_profile.up_actor' ] );
		if ( $hasPrivacyTable ) {
			$queryBuilder->select( 'ufp_privacy' )->leftJoin(
				'user_fields_privacy',
				null,
				[
					'ufp_actor = user_profile.up_actor',
					'ufp_field_key' => 'up_about',
				]
			);
		}
		$rows = $queryBuilder->caller( __METHOD__ )->fetchResultSet();

		$services = $this->getServiceContainer();
		$options = $services->getUserOptionsManager();
		$userFactory = $services->getUserFactory();

		$aboutField = $this->getAboutField();
		if ( $aboutField === null ) {
			$this->output( "UserProfileV2 has no profile-aboutme field; skipping about text.\n" );
			return 0;
		}
		$maxLength = ProfileFields::getMaxLength( $aboutField );

		$migrated = $skipped = $skippedPrivacy = $truncated = $errors = 0;
		foreach ( $rows as $row ) {
			if ( !$row->actor_user || trim( (string)$row->up_about ) === '' ) {
				continue;
			}

			// Skip hidden, friends-only or FOAF-only fields; UserProfileV2 has no visibility model.
			if ( $hasPrivacyTable && ( $row->ufp_privacy ?? 'public' ) !== 'public' ) {
				$skippedPrivacy++;
				continue;
			}

			$user = $userFactory->newFromId( (int)$row->actor_user );
			try {
				if ( !$user->isRegistered() || (string)$options->getOption( $user, 'profile-aboutme' ) !== '' ) {
					$skipped++;
					continue;
				}

				$about = trim( (string)$row->up_about );
				if ( $maxLength !== null && mb_strlen( $about ) > $maxLength ) {
					$about = mb_substr( $about, 0, $maxLength );
					$truncated++;
				}

				$error = ProfileFields::validateValue( $aboutField, $about );
				if ( $error !== null ) {
					throw new RuntimeException( "about text failed validation: {$error}" );
				}

				$this->output( "About: {$user->getName()}\n" );
				if ( !$dryRun ) {
					$options->setOption( $user, 'profile-aboutme', $about );
					$options->saveOptions( $user );
				}
				$migrated++;
			} catch ( Throwable $e ) {
				$this->error( "About: {$user->getName()}: {$e->getMessage()}" );
				$errors++;
			}
		}

		$this->output(
			"About: migrated {$migrated}, skipped {$skipped}, " .
			"skipped (non-public) {$skippedPrivacy}, truncated {$truncated}, " .
			"errors {$errors}\n"
		);
		return $errors;
	}

	private function getAboutField(): ?array {
		foreach ( ProfileFields::getEditableFields() as $field ) {
			if ( ( $field['key'] ?? '' ) === 'profile-aboutme' ) {
				return $field;
			}
		}

		return null;
	}

	private function migrateAvatars( bool $dryRun ): int {
		$services = $this->getServiceContainer();

		// SocialProfile may be uninstalled, so fall back to the local repo backend.
		if ( ExtensionRegistry::getInstance()->isLoaded( 'SocialProfile' ) ) {
			$sourceBackendObj = new SocialProfileFileBackend( 'avatars' );
			$sourceBackend = $sourceBackendObj->getFileBackend();
			$sourceDir = $sourceBackendObj->getContainerStoragePath();
		} else {
			$sourceBackend = $services->getRepoGroup()->getLocalRepo()->getBackend();
			$sourceDir = $sourceBackend->getContainerStoragePath( 'avatars' );
		}

		$targetBackendObj = new UserProfileV2AvatarBackend( 'upv2avatars' );
		$targetBackend = $targetBackendObj->getFileBackend();
		$targetDir = $targetBackendObj->getContainerStoragePath();

		$sourceExists = $sourceBackend->directoryExists( [ 'dir' => $sourceDir ] );
		if ( $sourceExists === false ) {
			$this->output( "No SocialProfile avatars; skipping avatars.\n" );
			return 0;
		}
		$files = $sourceExists === null ? null : $sourceBackend->getTopFileList( [ 'dir' => $sourceDir ] );
		if ( $files === null ) {
			$this->fatalError( 'Could not list SocialProfile avatars.' );
		}

		$formatter = $services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() );
		if ( !$dryRun ) {
			$status = $targetBackend->prepare( [ 'dir' => $targetDir ] );
			if ( !$status->isOK() ) {
				$this->fatalError( $formatter->getWikiText( $status ) );
			}
		}

		$userProfileV2Config = $services->getConfigFactory()->makeConfig( 'UserProfileV2' );
		$useGlobalAvatars = (bool)$userProfileV2Config->get( 'UserProfileV2UseGlobalAvatars' );
		$centralIdLookup = $services->getCentralIdLookup();

		$dbname = preg_quote( $this->getConfig()->get( MainConfigNames::DBname ), '/' );
		// SocialProfile writes all four sizes together; only the largest is needed.
		$pattern = '/^' . $dbname . '_(\d+)_l\.(png|gif|jpg|jpeg)$/';

		$userFactory = $services->getUserFactory();
		$cache = $services->getObjectCacheFactory()->getLocalClusterInstance();

		$migrated = $skipped = $errors = 0;
		foreach ( $files as $path ) {
			$name = basename( $path );
			if ( !preg_match( $pattern, $name, $matches ) ) {
				continue;
			}

			$userId = (int)$matches[1];
			$user = $userFactory->newFromId( $userId );
			try {
				if ( !$user->isRegistered() ) {
					$skipped++;
					continue;
				}

				// UserProfileV2 stores global avatars under the central ID.
				$targetUserId = $useGlobalAvatars
					? $centralIdLookup->centralIdFromLocalUser( $user )
					: $userId;
				if ( $targetUserId === 0 ) {
					throw new RuntimeException( 'no central account for a global avatar' );
				}

				foreach ( self::AVATAR_EXTENSIONS as $ext ) {
					$exists = $targetBackend->fileExists( [ 'src' => "$targetDir/avatar_$targetUserId.$ext" ] );
					if ( $exists === null ) {
						throw new RuntimeException( 'could not check the existing avatar' );
					}
					if ( $exists ) {
						$skipped++;
						continue 2;
					}
				}

				$this->output( "Avatar: {$user->getName()} ({$name})\n" );
				if ( !$dryRun ) {
					$status = $targetBackend->copy( [
						'src' => "$sourceDir/$name",
						'dst' => "$targetDir/avatar_$targetUserId.{$matches[2]}",
						'overwrite' => false,
					] );
					if ( !$status->isGood() ) {
						throw new RuntimeException( $formatter->getWikiText( $status ) );
					}

					$cacheKey = $useGlobalAvatars
						? $cache->makeGlobalKey( 'user', 'userprofilev2', 'avatar', $targetUserId )
						: $cache->makeKey( 'user', 'userprofilev2', 'avatar', $targetUserId );
					$cache->delete( $cacheKey );
				}
				$migrated++;
			} catch ( Throwable $e ) {
				$this->error( "Avatar: {$user->getName()}: {$e->getMessage()}" );
				$errors++;
			}
		}

		$this->output( "Avatars: migrated {$migrated}, skipped {$skipped}, errors {$errors}\n" );
		return $errors;
	}
}

// @codeCoverageIgnoreStart
return MigrateSocialProfileToUserProfileV2::class;
// @codeCoverageIgnoreEnd
