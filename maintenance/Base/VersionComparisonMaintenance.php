<?php

namespace Miraheze\MirahezeMagic\Maintenance\Base;

/**
 * Shared helpers for maintenance scripts that compare two MediaWiki versions.
 *
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
 * @ingroup MirahezeMagic
 * @author Universal Omega
 * @version 1.0
 */

use FilesystemIterator;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionRegistry;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use function array_is_list;
use function array_map;
use function array_merge;
use function basename;
use function define;
use function defined;
use function dirname;
use function file_get_contents;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function ksort;
use function preg_match;
use function preg_match_all;
use function str_replace;
use function strlen;
use function substr;
use function var_export;
use const GLOB_BRACE;
use const GLOB_NOSORT;
use const GLOB_ONLYDIR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PREG_SET_ORDER;

abstract class VersionComparisonMaintenance extends Maintenance {

	protected const string STAGING_PATH = '/srv/mediawiki-staging/';

	protected const string MANIFEST_PATTERN = '/^(extension|skin)(-[A-Za-z0-9._-]+)?\.json$/';

	protected const array MANIFEST_SKIPPED_DIRECTORIES = [ '.git', 'node_modules', 'tests', 'vendor' ];

	public function __construct() {
		parent::__construct();

		$this->addOption( 'from-version', 'MediaWiki version to start from', true, true );
		$this->addOption( 'to-version', 'MediaWiki version to end with', true, true );
	}

	protected function addEnabledOnlyOption(): void {
		$this->addOption( 'enabled-only', 'Only include extensions and skins loaded on this wiki' );
	}

	protected function getFromVersion(): string {
		return $this->getOption( 'from-version' );
	}

	protected function getToVersion(): string {
		return $this->getOption( 'to-version' );
	}

	protected function getVersionPath( string $version ): string {
		return self::STAGING_PATH . $version;
	}

	protected function findFilesRecursively( string $pattern ): array {
		$files = glob( $pattern );
		foreach ( glob( dirname( $pattern ) . '/*', GLOB_ONLYDIR | GLOB_NOSORT ) as $dir ) {
			$files = array_merge(
				[],
				...[ $files, $this->findFilesRecursively( $dir . '/' . basename( $pattern ) ) ]
			);
		}

		return $files;
	}

	protected function iterateFiles( string $root, array $skippedDirectories ): iterable {
		if ( !is_dir( $root ) ) {
			return;
		}

		$filter = new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static fn ( SplFileInfo $current ): bool => !$current->isDir() ||
				!in_array( $current->getFilename(), $skippedDirectories, true )
		);

		foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
			yield $file->getPathname();
		}
	}

	/**
	 * @return array<int, array{name: string, dir: string, path: string, file: string, info: array}>
	 */
	protected function findManifestFiles( string $version ): array {
		$base = $this->getVersionPath( $version );
		$entries = [];

		foreach ( glob( $base . '/{extensions,skins}/*', GLOB_BRACE | GLOB_ONLYDIR ) ?: [] as $dir ) {
			foreach ( $this->iterateFiles( $dir, self::MANIFEST_SKIPPED_DIRECTORIES ) as $file ) {
				if ( !preg_match( self::MANIFEST_PATTERN, basename( $file ) ) ) {
					continue;
				}

				$info = json_decode( file_get_contents( $file ), true );
				if ( !is_array( $info ) ) {
					$this->error( 'Could not parse ' . $file );
					continue;
				}

				if ( !isset( $info['manifest_version'] ) ) {
					continue;
				}

				$name = $info['name'] ?? basename( $dir );
				if ( $this->hasOption( 'enabled-only' ) && !ExtensionRegistry::getInstance()->isLoaded( $name ) ) {
					continue;
				}

				$entries[] = [
					'name' => $name,
					'dir' => basename( $dir ),
					'path' => $dir,
					'file' => $file,
					'info' => $info,
				];
			}
		}

		return $entries;
	}

	protected function findManifests( string $version ): array {
		$manifests = [];
		foreach ( $this->findManifestFiles( $version ) as $entry ) {
			$manifests[$entry['name']] = $entry['info'];
		}

		return $manifests;
	}

	protected function loadCoreSchema( string $version ): array {
		$base = $this->getVersionPath( $version ) . '/includes';
		if ( !is_file( $base . '/config-schema.php' ) ) {
			$this->fatalError( 'Missing ' . $base . '/config-schema.php' );
		}

		$defines = file_get_contents( $base . '/Defines.php' ) ?: '';
		$pattern = '/^define\(\s*\'([A-Z0-9_]+)\'\s*,\s*(-?\d+|\'[^\']*\'|true|false)\s*\)/m';
		if ( preg_match_all( $pattern, $defines, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( !defined( $match[1] ) ) {
					define( $match[1], json_decode( str_replace( "'", '"', $match[2] ) ) );
				}
			}
		}

		try {
			$schema = include $base . '/config-schema.php';
		} catch ( Throwable $e ) {
			$this->fatalError( 'Could not load config-schema.php for ' . $version . ': ' . $e->getMessage() );
		}

		return $schema;
	}

	protected function normalize( mixed $value ): mixed {
		if ( !is_array( $value ) ) {
			return $value;
		}

		$value = array_map( $this->normalize( ... ), $value );
		if ( !array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
	}

	protected function short( mixed $value ): string {
		$text = json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $text === false ) {
			$text = str_replace( "\n", ' ', var_export( $value, true ) );
		}

		return strlen( $text ) > 100 ? substr( $text, 0, 97 ) . '...' : $text;
	}
}
