<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List new or updated maintenance scripts between two MediaWiki versions.
 *
 * Usage:
 *     php run.php MirahezeMagic:FindPossibleUpgradeScripts --from-version=current_version --to-version=new_version
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
use Miraheze\MirahezeMagic\Maintenance\Base\VersionComparisonMaintenance;
use function array_key_exists;
use function basename;
use function count;
use function file_get_contents;
use function preg_match;
use function str_contains;
use function str_ends_with;

class FindPossibleUpgradeScripts extends VersionComparisonMaintenance {

	private const array SKIPPED_DIRECTORIES = [ '.git', 'node_modules', 'tests', 'vendor' ];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new or updated maintenance scripts between two MediaWiki versions.' );
	}

	public function execute() {
		$scripts = [];
		$this->findScripts( $this->getFromVersion(), $this->getToVersion(), $scripts );
		foreach ( $scripts as $script ) {
			$this->output( $script . $this->findExtendedClass( $script ) . "\n" );
		}

		$this->output( "\nCount: " . count( $scripts ) . "\n" );
	}

	private function findScripts( $fromVersion, $toVersion, &$scripts ) {
		$fromScripts = $this->findMaintenanceScripts( $fromVersion );
		$toScripts = $this->findMaintenanceScripts( $toVersion );
		foreach ( $toScripts as $script ) {
			$filename = basename( $script );
			if ( array_key_exists( $filename, $fromScripts ) ) {
				if ( $this->isScriptUpdated( $fromScripts[$filename], $script ) ) {
					$scripts[] = $script;
				}
			} else {
				$scripts[] = $script;
			}
		}
	}

	private function findMaintenanceScripts( $version ) {
		$scripts = [];

		foreach ( $this->iterateFiles( $this->getVersionPath( $version ), self::SKIPPED_DIRECTORIES ) as $file ) {
			if ( str_ends_with( $file, '.php' ) && str_contains( $file, '/maintenance/' ) ) {
				$scripts[basename( $file )] = $file;
			}
		}

		return $scripts;
	}

	/**
	 * @param string $oldScript @phan-unused-param
	 * @param string $newScript @phan-unused-param
	 */
	private function isScriptUpdated( $oldScript, $newScript ) {
		// TO-DO
	}

	private function findExtendedClass( $script ) {
		$file = file_get_contents( $script );
		if ( preg_match( '/extends\s+([^\s]+)\s+/', $file, $matches ) ) {
			if ( $matches[1] !== Maintenance::class ) {
				return ' (' . $matches[1] . ')';
			}
		}

		return '';
	}
}

// @codeCoverageIgnoreStart
return FindPossibleUpgradeScripts::class;
// @codeCoverageIgnoreEnd
