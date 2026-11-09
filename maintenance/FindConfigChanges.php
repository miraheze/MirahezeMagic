<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List new, removed or changed default configuration settings between two MediaWiki versions.
 *
 * Usage:
 *     php run.php MirahezeMagic:FindConfigChanges --from-version=current_version --to-version=new_version
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

use Miraheze\MirahezeMagic\Maintenance\Base\VersionComparisonMaintenance;
use function array_diff;
use function array_diff_key;
use function array_intersect_key;
use function array_is_list;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function is_array;
use function ksort;
use function str_contains;
use function str_starts_with;
use function substr;

class FindConfigChanges extends VersionComparisonMaintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new, removed or changed default configuration settings between two MediaWiki versions.' );

		$this->addEnabledOnlyOption();
		$this->addOption( 'filter', 'Only show settings whose name contains this text', false, true );
	}

	public function execute() {
		$toVersion = $this->getToVersion();
		$from = $this->collect( $this->getFromVersion() );
		$to = $this->collect( $toVersion );
		$obsolete = $this->loadCoreSchema( $toVersion )['obsolete-config'] ?? [];
		$filter = $this->getOption( 'filter' );

		$new = [];
		$removed = [];
		$changed = [];

		foreach ( $to as $name => $entry ) {
			if ( $filter !== null && !str_contains( $name, $filter ) ) {
				continue;
			}

			if ( !isset( $from[$name] ) ) {
				$new[$name] = $entry;
			} elseif ( $from[$name]['value'] !== $entry['value'] ) {
				$changed[$name] = [ $from[$name], $entry ];
			}
		}

		foreach ( $from as $name => $entry ) {
			if ( !isset( $to[$name] ) && ( $filter === null || str_contains( $name, $filter ) ) ) {
				$removed[$name] = $entry;
			}
		}

		foreach ( $new as $name => $entry ) {
			$this->output( 'NEW $' . $name . ' [' . $entry['source'] . '] ' . $this->short( $entry['value'] ) . "\n" );
		}

		foreach ( $removed as $name => $entry ) {
			$note = isset( $obsolete[substr( $name, 2 )] ) ? ' obsolete: ' . $obsolete[substr( $name, 2 )] : '';
			$this->output( 'REMOVED $' . $name . ' [' . $entry['source'] . ']' . $this->setMarker( $name ) . $note . "\n" );
		}

		foreach ( $changed as $name => [ $old, $current ] ) {
			$this->output(
				'CHANGED $' . $name . ' [' . $current['source'] . ']' . $this->setMarker( $name ) . ' ' .
				$this->describeChange( $old['value'], $current['value'] ) . "\n"
			);
		}

		$this->output(
			"\nNew: " . count( $new ) . ', removed: ' . count( $removed ) . ', changed: ' . count( $changed ) . "\n"
		);
	}

	private function describeChange( mixed $old, mixed $new ): string {
		if ( !is_array( $old ) || !is_array( $new ) ) {
			return $this->short( $old ) . ' => ' . $this->short( $new );
		}

		$parts = [];
		if ( array_is_list( $old ) && array_is_list( $new ) ) {
			$oldItems = array_map( 'json_encode', $old );
			$newItems = array_map( 'json_encode', $new );
			foreach ( array_diff( $newItems, $oldItems ) as $item ) {
				$parts[] = '+' . $item;
			}

			foreach ( array_diff( $oldItems, $newItems ) as $item ) {
				$parts[] = '-' . $item;
			}
		} else {
			foreach ( array_keys( array_diff_key( $new, $old ) ) as $key ) {
				$parts[] = '+' . $key;
			}

			foreach ( array_keys( array_diff_key( $old, $new ) ) as $key ) {
				$parts[] = '-' . $key;
			}

			foreach ( array_intersect_key( $new, $old ) as $key => $value ) {
				if ( $old[$key] !== $value ) {
					$parts[] = '~' . $key;
				}
			}
		}

		$text = implode( ' ', $parts );
		return $text === '' ? $this->short( $old ) . ' => ' . $this->short( $new ) : $text;
	}

	private function setMarker( string $name ): string {
		global $wgConf;
		return isset( $wgConf->settings[$name] ) ? ' (set in config)' : '';
	}

	private function collect( string $version ): array {
		$configs = [];
		$schema = $this->loadCoreSchema( $version );
		$inverse = $schema['config-schema-inverse'] ?? [];
		$dynamic = $inverse['dynamicDefault'] ?? [];

		foreach ( array_keys( ( $inverse['default'] ?? [] ) + $dynamic ) as $name ) {
			$value = $inverse['default'][$name] ?? null;
			if ( isset( $dynamic[$name] ) ) {
				$value = [ 'default' => $value, 'dynamic' => $dynamic[$name] ];
			}

			$configs['wg' . $name] = [
				'value' => $this->normalize( $value ),
				'source' => 'core',
			];
		}

		foreach ( $this->findManifests( $version ) as $source => $info ) {
			$this->readManifestConfig( $info, $source, $configs );
		}

		ksort( $configs );
		return $configs;
	}

	private function readManifestConfig( array $info, string $source, array &$configs ): void {
		$config = $info['config'] ?? [];
		$v2 = ( $info['manifest_version'] ?? 1 ) >= 2;
		$prefix = $v2 ? ( $info['config_prefix'] ?? 'wg' ) : ( $config['_prefix'] ?? 'wg' );

		foreach ( $config as $name => $entry ) {
			if ( $name === '_prefix' || str_starts_with( $name, '@' ) ) {
				continue;
			}

			$value = $v2 ? ( is_array( $entry ) ? ( $entry['value'] ?? null ) : null ) : $entry;
			$configs[$prefix . $name] = [
				'value' => $this->normalize( $value ),
				'source' => $source,
			];
		}
	}
}

// @codeCoverageIgnoreStart
return FindConfigChanges::class;
// @codeCoverageIgnoreEnd
