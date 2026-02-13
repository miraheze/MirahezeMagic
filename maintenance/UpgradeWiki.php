<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * JSON format:
 * {
 *   "mwversion": "1.45",
 *   "pre_patches": [
 *     "/path/to/a.sql",
 *     { "file": "/path/to/b.sql" }
 *   ],
 *   "maintenance": [
 *     {
 *       "class": "Miraheze\\MirahezeMagic\\Maintenance\\ChangeMediaWikiVersion",
 *       "options": { "mwversion": "1.45" }
 *     },
 *     {
 *       "class": "MediaWiki\\Extension\\CentralAuth\\Maintenance\\FixRenameUserLocalLogs",
 *       "options": { "logwiki": "metawiki", "fix": true }
 *     }
 *   ],
 *   "post_patches": [
 *     "/path/to/after.sql"
 *   ]
 * }
 *
 * Notes:
 * - The runner always injects wiki context into child scripts:
 *   - 'wikidb' is set for MwSql
 *   - 'wiki' is set for maintenance scripts (unless you override it explicitly)
 * - For maintenance scripts:
 *   - "class" is required
 *   - "options" is optional (key => value)
 *   - "args" is optional (array of positional args)
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

use MediaWiki\Maintenance\Maintenance;
use MwSql;
use function basename;
use function class_exists;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;

class UpgradeWiki extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Run a wiki upgrade defined in a JSON file (patches + maintenance steps).' );
		$this->addOption( 'json', 'Path to JSON file.', true, true );
		$this->requireExtension( 'MirahezeMagic' );
	}

	public function execute() {
		$wiki = $this->getOption( 'wiki' );
		$jsonPath = $this->getOption( 'json' );
		$json = $this->loadJson( $jsonPath );

		$this->output( "=== Running based on JSON '$jsonPath' for wiki '$wiki' ===\n" );
		$mwVersion = $json['mwversion'] ?? null;
		if ( $mwVersion !== null ) {
			$this->output( "=== Switching MediaWiki version to $mwVersion for wiki '$wiki' ===\n" );
			$this->runMaintenanceClass(
				$wiki,
				ChangeMediaWikiVersion::class,
				[ 'mwversion' => $mwVersion ],
				[]
			);
		}

		$this->runPatchesSection( $wiki, $json, 'pre_patches', "=== Running pre-maintenance SQL patches ===\n" );
		$this->runMaintenanceSection( $wiki, $json );
		$this->runPatchesSection( $wiki, $json, 'post_patches', "=== Running post-maintenance SQL patches ===\n" );
		$this->output( "All steps completed.\n" );
	}

	private function runPatchesSection( string $wiki, array $json, string $key, string $header ): void {
		$items = $json[$key] ?? [];
		if ( $items === [] ) {
			return;
		}

		if ( !is_array( $items ) ) {
			$this->fatalError( "JSON key '$key' must be an array." );
		}

		$this->output( $header );
		foreach ( $items as $item ) {
			$filename = $this->normalizePatchItemToFilename( $item, $key );
			$this->runSqlFile( $wiki, $filename );
		}
	}

	private function runMaintenanceSection( string $wiki, array $json ): void {
		$items = $json['maintenance'] ?? [];
		if ( $items === [] ) {
			return;
		}

		if ( !is_array( $items ) ) {
			$this->fatalError( "JSON key 'maintenance' must be an array." );
		}

		$this->output( "=== Running maintenance scripts ===\n" );
		foreach ( $items as $idx => $item ) {
			if ( !is_array( $item ) ) {
				$this->fatalError( "maintenance[$idx] must be an object." );
			}

			$class = $item['class'] ?? null;
			if ( !is_string( $class ) || $class === '' ) {
				$this->fatalError( "maintenance[$idx].class must be a non-empty string." );
			}

			$options = $item['options'] ?? [];
			$args = $item['args'] ?? [];
			if ( $options !== [] && !is_array( $options ) ) {
				$this->fatalError( "maintenance[$idx].options must be an object (key/value) if present." );
			}

			if ( $args !== [] && !is_array( $args ) ) {
				$this->fatalError( "maintenance[$idx].args must be an array if present." );
			}

			$this->output( "==> Maintenance: $class\n" );
			$this->runMaintenanceClass( $wiki, $class, $options, $args );
		}
	}

	private function runSqlFile( string $wiki, string $filename ): void {
		$this->output( "==> SQL: " . basename( $filename ) . "\n" );
		$maint = new MwSql();
		$maint->setOption( 'wikidb', $wiki );
		$maint->setArg( 0, $filename );
		$maint->execute();
	}

	private function runMaintenanceClass( string $wiki, string $class, array $options, array $args ): void {
		if ( !class_exists( $class ) ) {
			$this->fatalError( "Maintenance class not found: $class" );
		}

		/** @var Maintenance $maint */
		$maint = new $class();
		if ( !$this->hasOptionKey( $options, 'wiki' ) ) {
			$maint->setOption( 'wiki', $wiki );
		}

		foreach ( $options as $key => $value ) {
			if ( !is_string( $key ) || $key === '' ) {
				$this->fatalError( "Invalid option key for $class (must be non-empty string)." );
			}

			if (
				$value === null ||
				is_string( $value ) ||
				is_int( $value ) ||
				is_bool( $value )
			) {
				$maint->setOption( $key, $value );
				continue;
			}

			$this->fatalError(
				"Option '$key' for $class must be string/int/bool/null (got non-scalar)."
			);
		}

		$argIndex = 0;
		foreach ( $args as $arg ) {
			if ( !is_string( $arg ) && !is_int( $arg ) ) {
				$this->fatalError( "Args for $class must be strings/ints." );
			}

			$maint->setArg( $argIndex, (string)$arg );
			$argIndex++;
		}

		$maint->execute();
	}

	private function hasOptionKey( array $options, string $key ): bool {
		return isset( $options[$key] );
	}

	private function normalizePatchItemToFilename( mixed $item, string $sectionKey ): string {
		if ( is_string( $item ) && $item !== '' ) {
			return $item;
		}

		if ( is_array( $item ) ) {
			$file = $item['file'] ?? null;
			if ( is_string( $file ) && $file !== '' ) {
				return $file;
			}
		}

		$this->fatalError(
			"Each entry in '$sectionKey' must be either a string filename or {\"file\": \"...\"}."
		);
	}

	private function loadJson( string $filename ): array {
		$json = file_get_contents( $filename );
		if ( $json === false ) {
			$this->fatalError( "Failed to read JSON file: $filename" );
		}

		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			$this->fatalError( "JSON file did not decode to an object: $filename" );
		}

		return $data;
	}
}

// @codeCoverageIgnoreStart
return UpgradeWiki::class;
// @codeCoverageIgnoreEnd
