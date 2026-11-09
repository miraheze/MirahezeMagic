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
use RuntimeException;
use Throwable;

/**
 * Migrate SocialProfile about text and largest avatars to UserProfileV2 on Miraheze.
 *
 * @since 1.46
 * @ingroup Maintenance
 */
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
		$dbr = $this->getDB( DB_REPLICA );
		if ( !$dbr->tableExists( 'user_profile', __METHOD__ ) ) {
			$this->output( "No user_profile table; skipping about text.\n" );
			return 0;
		}
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'up_about', 'actor_user' ] )
			->from( 'user_profile' )
			->join( 'actor', null, [ 'actor.actor_id = user_profile.up_actor' ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$services = $this->getServiceContainer();
		$options = $services->getUserOptionsManager();
		$userFactory = $services->getUserFactory();
		$migrated = $skipped = $errors = 0;
		foreach ( $rows as $row ) {
			if ( !$row->actor_user || trim( $row->up_about ) === '' ) {
				continue;
			}
			$user = $userFactory->newFromId( (int)$row->actor_user );
			try {
				if ( !$user->isRegistered() || (string)$options->getOption( $user, 'profile-aboutme' ) !== '' ) {
					$skipped++;
					continue;
				}
				$this->output( "About: {$user->getName()}\n" );
				if ( !$dryRun ) {
					$options->setOption( $user, 'profile-aboutme', (string)$row->up_about );
					$options->saveOptions( $user );
				}
				$migrated++;
			} catch ( Throwable $e ) {
				$this->error( "About: {$user->getName()}: {$e->getMessage()}" );
				$errors++;
			}
		}
		$this->output( "About: migrated {$migrated}, skipped {$skipped}, errors {$errors}\n" );
		return $errors;
	}

	private function migrateAvatars( bool $dryRun ): int {
		$services = $this->getServiceContainer();
		// Both extensions use Miraheze's local repository backend.
		$backend = $services->getRepoGroup()->getLocalRepo()->getBackend();
		$sourceDir = $backend->getContainerStoragePath( 'avatars' );
		$targetDir = $backend->getContainerStoragePath( 'upv2avatars' );
		$sourceExists = $backend->directoryExists( [ 'dir' => $sourceDir ] );
		if ( $sourceExists === false ) {
			$this->output( "No SocialProfile avatars; skipping avatars.\n" );
			return 0;
		}
		$files = $sourceExists === null ? null : $backend->getTopFileList( [ 'dir' => $sourceDir ] );
		if ( $files === null ) {
			$this->fatalError( 'Could not list SocialProfile avatars.' );
		}
		$formatter = $services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() );
		if ( !$dryRun ) {
			$status = $backend->prepare( [ 'dir' => $targetDir ] );
			if ( !$status->isOK() ) {
				$this->fatalError( $formatter->getWikiText( $status ) );
			}
		}
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
				foreach ( self::AVATAR_EXTENSIONS as $ext ) {
					$exists = $backend->fileExists( [ 'src' => "$targetDir/avatar_$userId.$ext" ] );
					if ( $exists === null ) {
						throw new RuntimeException( 'Could not check the existing avatar.' );
					}
					if ( $exists ) {
						$skipped++;
						continue 2;
					}
				}
				$this->output( "Avatar: {$user->getName()} ({$name})\n" );
				if ( !$dryRun ) {
					$status = $backend->copy( [
						'src' => "$sourceDir/$name",
						'dst' => "$targetDir/avatar_$userId.{$matches[2]}",
						'overwrite' => false,
					] );
					if ( !$status->isGood() ) {
						throw new RuntimeException( $formatter->getWikiText( $status ) );
					}
					$cache->delete( $cache->makeKey( 'user', 'userprofilev2', 'avatar', $userId ) );
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
