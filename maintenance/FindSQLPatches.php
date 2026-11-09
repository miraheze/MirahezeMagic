<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List new or updated SQL patches between two MediaWiki versions.
 *
 * Usage:
 *     php run.php MirahezeMagic:FindSQLPatches --from-version=current_version --to-version=new_version
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
use function array_key_exists;
use function basename;
use function count;
use function str_contains;

class FindSQLPatches extends VersionComparisonMaintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new or updated SQL patches between two MediaWiki versions.' );
	}

	public function execute() {
		$patches = [];
		$this->findPatches( $this->getFromVersion(), $this->getToVersion(), $patches );
		foreach ( $patches as $patch ) {
			$this->output( $patch . "\n" );
		}

		$this->output( "\nCount: " . count( $patches ) . "\n" );
	}

	private function findPatches( $fromVersion, $toVersion, &$patches ) {
		$fromPatches = $this->findSqlPatches( $fromVersion );
		$toPatches = $this->findSqlPatches( $toVersion );
		foreach ( $toPatches as $patch ) {
			$filename = basename( $patch );
			if ( array_key_exists( $filename, $fromPatches ) ) {
				if ( $this->isPatchUpdated( $fromPatches[$filename], $patch ) ) {
					$patches[] = $patch;
				}
			} else {
				$patches[] = $patch;
			}
		}
	}

	private function findSqlPatches( $version ) {
		$patches = [];

		$files = $this->findFilesRecursively( $this->getVersionPath( $version ) . '/**/*.sql' );

		foreach ( $files as $file ) {
			if (
				str_contains( $file, '/postgres/' ) ||
				str_contains( $file, '/sqlite/' ) ||
				str_contains( $file, '/tests/' )
			) {
				continue;
			}

			$patches[basename( $file )] = $file;
		}

		return $patches;
	}

	/**
	 * @param string $oldPatch @phan-unused-param
	 * @param string $newPatch @phan-unused-param
	 */
	private function isPatchUpdated( $oldPatch, $newPatch ) {
		// TO-DO
	}
}

// @codeCoverageIgnoreStart
return FindSQLPatches::class;
// @codeCoverageIgnoreEnd
