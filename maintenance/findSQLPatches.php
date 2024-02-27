<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List new or updated SQL patches between two MediaWiki versions.
 *
 * Usage:
 *     php findSQLPatches.php --from-version=current_version --to-version=new_version
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

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;

class FindSQLPatches extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new or updated SQL patches between two MediaWiki versions.' );

		$this->addOption( 'from-version', 'MediaWiki version to start from', true, true );
		$this->addOption( 'to-version', 'MediaWiki version to end with', true, true );
	}

	public function execute() {
		$fromVersion = $this->getOption( 'from-version' );
		$toVersion = $this->getOption( 'to-version' );
		$patches = [];
		$this->findPatches( $fromVersion, $toVersion, $patches );
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

		$files = $this->findFilesRecursively( '/srv/mediawiki-staging/' . $version . '/**/*.sql' );

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

	private function isPatchUpdated( $oldPatch, $newPatch ) {
		// TO-DO
	}

	private function findFilesRecursively( $pattern ) {
		$files = glob( $pattern );
		foreach ( glob( dirname( $pattern ) . '/*', GLOB_ONLYDIR | GLOB_NOSORT ) as $dir ) {
			$files = array_merge(
				[],
				...[ $files, $this->findFilesRecursively( $dir . '/' . basename( $pattern ) ) ]
			);
		}

		return $files;
	}
}

$maintClass = FindSQLPatches::class;
require_once RUN_MAINTENANCE_IF_MAIN;
