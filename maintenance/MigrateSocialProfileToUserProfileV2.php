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
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionRegistry;
use Telepedia\UserProfileV2\Avatar\UserProfileV2AvatarBackend;
use Telepedia\UserProfileV2\ProfileFields;
use Throwable;
use Wikimedia\FileBackend\FileBackend;

/**
 * Migrate SocialProfile "about me" text and avatars to UserProfileV2.
 *
 * Gifts, friends, foes and other SocialProfile data are intentionally ignored.
 *
 * @since 1.46
 * @ingroup Maintenance
 */
class MigrateSocialProfileToUserProfileV2 extends Maintenance {

	private const AVATAR_SIZES = [ 'l', 'ml', 'm', 's' ];
	private const AVATAR_EXTENSIONS = [ 'png', 'gif', 'jpg', 'jpeg' ];

	/** @inheritDoc */
	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Migrate SocialProfile "about me" text and avatars to UserProfileV2.'
		);
		$this->addOption(
			'dry-run',
			'Report what would be changed without writing anything.'
		);
		$this->addOption(
			'avatar-key',
			'SocialProfile avatar file prefix (defaults to $wgAvatarKey or the database name).',
			withArg: true
		);

		// SocialProfile is old-style and not in ExtensionRegistry; check its data directly.
		$this->requireExtension( 'UserProfileV2' );
	}

	/** @inheritDoc */
	public function execute(): void {
		$dryRun = (bool)$this->getOption( 'dry-run' );
		$avatarKey = (string)( $this->getOption( 'avatar-key' ) ?: (
			$GLOBALS['wgAvatarKey'] ?? $this->getConfig()->get( MainConfigNames::DBname )
		) );
		$uploadDir = $this->getConfig()->get( MainConfigNames::UploadDirectory );

		if ( $dryRun ) {
			$this->output( "DRY RUN: no changes will be written. Omit --dry-run to apply.\n\n" );
		}

		$errors = $this->migrateAboutText( !$dryRun );
		$errors += $this->migrateAvatars( !$dryRun, $avatarKey, $uploadDir );

		if ( $errors > 0 ) {
			$this->fatalError( "Migration finished with {$errors} error(s)." );
		}

		$this->output( "\nDone.\n" );
	}

	private function migrateAboutText( bool $execute ): int {
		$dbr = $this->getReplicaDB();
		if ( !$dbr->tableExists( 'user_profile', __METHOD__ ) ) {
			$this->output( "user_profile table does not exist; skipping about text.\n" );
			return 0;
		}

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'up_about', 'actor_user' ] )
			->from( 'user_profile' )
			->join( 'actor', null, [ 'actor.actor_id = user_profile.up_actor' ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$optionsManager = $this->getServiceContainer()->getUserOptionsManager();
		$userFactory = $this->getServiceContainer()->getUserFactory();
		$aboutLimit = $this->getAboutLimit();

		$migrated = 0;
		$skipped = 0;
		$errors = 0;

		foreach ( $res as $row ) {
			$about = (string)$row->up_about;
			$userId = (int)$row->actor_user;
			if ( trim( $about ) === '' || $userId === 0 ) {
				continue;
			}

			$user = $userFactory->newFromId( $userId );
			if ( !$user->isRegistered() ) {
				$this->output( "About: skipping user ID {$userId} (not registered)\n" );
				$skipped++;
				continue;
			}

			$current = $optionsManager->getOption( $user, 'profile-aboutme' );
			if ( $current !== null && $current !== '' ) {
				$this->output( "About: skipping {$user->getName()} (already set)\n" );
				$skipped++;
				continue;
			}

			$aboutLength = mb_strlen( $about );
			if ( $aboutLimit !== null && $aboutLimit > 0 && $aboutLength > $aboutLimit ) {
				$this->output(
					"About: {$user->getName()} is {$aboutLength} chars " .
					"(UserProfileV2's editor limit is {$aboutLimit}).\n"
				);
			} elseif ( $aboutLimit === null && $aboutLength > 200 ) {
				$this->output(
					"About: {$user->getName()} is {$aboutLength} chars " .
					"(exceeds the default editor limit of 200).\n"
				);
			}

			$this->output(
				"About: {$user->getName()} (#{$userId}), {$aboutLength} chars\n"
			);
			if ( !$execute ) {
				$migrated++;
				continue;
			}

			try {
				$optionsManager->setOption( $user, 'profile-aboutme', $about );
				$optionsManager->saveOptions( $user );
				$migrated++;
			} catch ( Throwable $e ) {
				$this->error( "About: ERROR for {$user->getName()}: {$e->getMessage()}" );
				$errors++;
			}
		}

		$this->output( "About: migrated {$migrated}, skipped {$skipped}, errors {$errors}\n" );
		return $errors;
	}

	private function getAboutLimit(): ?int {
		if ( class_exists( ProfileFields::class ) ) {
			foreach ( ProfileFields::getFields() as $field ) {
				if ( ( $field['key'] ?? '' ) === 'profile-aboutme' ) {
					return ProfileFields::getMaxLength( $field );
				}
			}

			// The about field is hidden or has been removed from the configuration.
			return 0;
		}

		// Older UserProfileV2 versions hard-coded the about field to 200 characters.
		return 200;
	}

	/**
	 * Find SocialProfile avatar files from the configured file backend.
	 *
	 * @return array<int, array<string, array<string, array{path: string, backend: ?FileBackend,
	 *   name: string}>>>|null Null if no source could be read; empty array if readable but empty.
	 */
	private function getAvatarSources( string $avatarKey, string $uploadDir ): ?array {
		$pattern = '/^' . preg_quote( $avatarKey, '/' ) . '_(\d+)_(l|ml|m|s)\.(png|gif|jpg|jpeg)$/i';
		$avatars = [];
		$failed = false;

		$sourceBackend = $GLOBALS['wgSocialProfileFileBackend'] ?? null;
		if ( !$sourceBackend ) {
			$upv2Config = $this->getServiceContainer()->getConfigFactory()
				->makeConfig( 'UserProfileV2' );
			$sourceBackend = $upv2Config->get( 'UserProfileV2Backend' );
		}

		if ( $sourceBackend ) {
			try {
				$fileBackend = $this->getServiceContainer()->getFileBackendGroup()
					->get( $sourceBackend );
				$dir = $fileBackend->getContainerStoragePath( 'avatars' );
				$listed = $this->listAvatarFiles( $fileBackend, $dir, $pattern );
				if ( $listed === null ) {
					$failed = true;
					$this->output(
						"Could not list source backend '{$sourceBackend}'; trying local files.\n"
					);
				} else {
					$avatars = $listed;
					if ( $avatars ) {
						return $avatars;
					}
				}
			} catch ( Throwable $e ) {
				$failed = true;
				$this->output(
					"Source backend '{$sourceBackend}' unavailable ({$e->getMessage()}); " .
					"trying local files.\n"
				);
			}
		}

		$avatarDir = $uploadDir . '/avatars';
		if ( is_dir( $avatarDir ) ) {
			$files = scandir( $avatarDir );
			if ( $files === false ) {
				$this->error( "Avatar: ERROR could not read local directory {$avatarDir}." );
				$failed = true;
			} else {
				foreach ( $files as $name ) {
					$parsed = $this->parseAvatarName( $name, $pattern );
					if ( $parsed === null ) {
						continue;
					}
					[ $userId, $size, $ext ] = $parsed;
					$avatars[$userId][$size][$ext] = [
						'path' => $avatarDir . '/' . $name,
						'backend' => null,
						'name' => $name,
					];
				}
			}
		}

		if ( $avatars ) {
			return $avatars;
		}

		return $failed ? null : [];
	}

	/**
	 * @return array<int, array<string, array<string, array{path: string, backend: FileBackend,
	 *   name: string}>>>|null Null if the listing failed; partial results are never returned.
	 */
	private function listAvatarFiles(
		FileBackend $fileBackend,
		string $dir,
		string $pattern
	): ?array {
		$files = $fileBackend->getFileList( [
			'dir' => $dir,
			'topOnly' => true,
			'adviseStat' => false,
		] );
		if ( $files === null ) {
			return null;
		}

		$avatars = [];
		foreach ( $files as $path ) {
			$name = basename( $path );
			$parsed = $this->parseAvatarName( $name, $pattern );
			if ( $parsed === null ) {
				continue;
			}
			[ $userId, $size, $ext ] = $parsed;
			$avatars[$userId][$size][$ext] = [
				'path' => rtrim( $dir, '/' ) . '/' . $name,
				'backend' => $fileBackend,
				'name' => $name,
			];
		}

		return $avatars;
	}

	/**
	 * @return array{int, string, string}|null User ID, size and extension.
	 */
	private function parseAvatarName( string $name, string $pattern ): ?array {
		if ( !preg_match( $pattern, $name, $matches ) ) {
			return null;
		}
		return [ (int)$matches[1], strtolower( $matches[2] ), strtolower( $matches[3] ) ];
	}

	private function migrateAvatars(
		bool $execute,
		string $avatarKey,
		string $uploadDir
	): int {
		$avatars = $this->getAvatarSources( $avatarKey, $uploadDir );
		if ( $avatars === null ) {
			$this->error( "Avatar: ERROR could not read any SocialProfile avatar source." );
			return 1;
		}
		if ( !$avatars ) {
			$this->output( "No SocialProfile avatars found; skipping avatars.\n" );
			return 0;
		}

		$userFactory = $this->getServiceContainer()->getUserFactory();
		$upv2Config = $this->getServiceContainer()->getConfigFactory()
			->makeConfig( 'UserProfileV2' );
		$useGlobalAvatars = (bool)$upv2Config->get( 'UserProfileV2UseGlobalAvatars' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' );
		$cacheType = $upv2Config->get( 'UserProfileV2CacheType' );
		$cacheFactory = $this->getServiceContainer()->getObjectCacheFactory();
		$cache = $cacheType
			? $cacheFactory->getInstance( $cacheType )
			: $cacheFactory->getLocalClusterInstance();
		$backendName = $upv2Config->get( 'UserProfileV2Backend' );
		$backend = new UserProfileV2AvatarBackend( 'upv2avatars' );
		$statusFormatter = $this->getServiceContainer()->getFormatterFactory()
			->getStatusFormatter( RequestContext::getMain() );

		$migrated = 0;
		$skipped = 0;
		$errors = 0;

		foreach ( $avatars as $userId => $sizes ) {
			$source = null;
			$sourceExt = null;
			foreach ( self::AVATAR_SIZES as $size ) {
				if ( !isset( $sizes[$size] ) ) {
					continue;
				}
				foreach ( self::AVATAR_EXTENSIONS as $ext ) {
					if ( isset( $sizes[$size][$ext] ) ) {
						$source = $sizes[$size][$ext];
						$sourceExt = $ext;
						break 2;
					}
				}
			}

			if ( $source === null ) {
				continue;
			}

			$user = $userFactory->newFromId( $userId );
			if ( !$user->isRegistered() ) {
				$this->output( "Avatar: skipping user ID {$userId} (not registered)\n" );
				$skipped++;
				continue;
			}

			$avatarUserId = $userId;
			if ( $useGlobalAvatars ) {
				$centralUser = CentralAuthUser::getPrimaryInstanceByName( $user->getName() );
				if ( !$centralUser || !$centralUser->getId() ) {
					$this->output( "Avatar: skipping {$user->getName()} (no CentralAuth ID)\n" );
					$skipped++;
					continue;
				}
				$avatarUserId = $centralUser->getId();
			}

			$exists = false;
			foreach ( self::AVATAR_EXTENSIONS as $ext ) {
				$existsForExt = $this->avatarExists(
					$execute, $backend, $backendName, $uploadDir, $avatarUserId, $ext
				);
				if ( $existsForExt === null ) {
					$this->error(
						"Avatar: ERROR checking existing avatar for {$user->getName()} ({$ext})"
					);
					$errors++;
					continue 2;
				}
				if ( $existsForExt ) {
					$exists = true;
					break;
				}
			}

			if ( $exists ) {
				$this->output( "Avatar: skipping {$user->getName()} (already set)\n" );
				$skipped++;
				continue;
			}

			$this->output(
				"Avatar: {$user->getName()} (#{$userId}) " .
				$source['name'] . " -> avatar_{$avatarUserId}.{$sourceExt}\n"
			);
			if ( !$execute ) {
				$migrated++;
				continue;
			}

			try {
				$srcPath = $source['path'];
				if ( $source['backend'] ) {
					$localFile = $source['backend']->getLocalReference( [ 'src' => $srcPath ] );
					if ( !$localFile ) {
						$this->error( "Avatar: ERROR reading {$source['name']}" );
						$errors++;
						continue;
					}
					$srcPath = $localFile->getPath();
				}

				$status = $backend->getFileBackend()->store( [
					'src' => $srcPath,
					'dst' => $backend->getContainerStoragePath() .
						'/avatar_' . $avatarUserId . '.' . $sourceExt,
					'overwrite' => false,
				] );
				if ( !$status->isGood() ) {
					$this->error(
						"Avatar: ERROR for {$user->getName()}: " .
						$statusFormatter->getWikiText( $status )
					);
					$errors++;
					continue;
				}

				$key = $useGlobalAvatars
					? $cache->makeGlobalKey( 'user', 'userprofilev2', 'avatar', $avatarUserId )
					: $cache->makeKey( 'user', 'userprofilev2', 'avatar', $avatarUserId );
				$cache->delete( $key );

				$migrated++;
			} catch ( Throwable $e ) {
				$this->error( "Avatar: ERROR for {$user->getName()}: {$e->getMessage()}" );
				$errors++;
			}
		}

		$this->output( "Avatars: migrated {$migrated}, skipped {$skipped}, errors {$errors}\n" );
		return $errors;
	}

	private function avatarExists(
		bool $execute,
		UserProfileV2AvatarBackend $backend,
		string $backendName,
		string $uploadDir,
		int $userId,
		string $ext
	): ?bool {
		if ( $execute ) {
			return $backend->fileExists( 'avatar_', $userId, $ext );
		}

		if ( $backendName ) {
			$fileBackend = $this->getServiceContainer()->getFileBackendGroup()
				->get( $backendName );
			return $fileBackend->fileExists( [
				'src' => $fileBackend->getContainerStoragePath( 'upv2avatars' ) .
					'/avatar_' . $userId . '.' . $ext,
			] );
		}

		return is_file( $uploadDir . '/upv2avatars/avatar_' . $userId . '.' . $ext );
	}
}

// @codeCoverageIgnoreStart
return MigrateSocialProfileToUserProfileV2::class;
// @codeCoverageIgnoreEnd
