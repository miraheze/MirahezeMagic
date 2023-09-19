<?php

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
 * @ingroup Maintenance
 * @author Universal Omega
 * @author Paladox
 * @version 1.0
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class FindSQLPatches extends Maintenance {
		public function __construct() {
				parent::__construct();

				$this->addDescription( 'List new or updated SQL patches between two MediaWiki versions.' );

				$this->addOption( 'from-version', 'Path for MediaWiki version to start from', true, true );
				$this->addOption( 'to-version', 'Path for MediaWiki version to end with', true, true );
		}

		public function execute() {
				$fromVersionPath = $this->getOption( 'from-version-path' );
				$toVersionPath = $this->getOption( 'to-version-path' );
				$patches = [];
				$this->findPatches( $fromVersionPath, $toVersionPath, $patches );
				foreach ( $patches as $patch ) {
						$this->output( $patch . "\n" );
				}

				$this->output( "\nCount: " . count( $patches ) . "\n" );
		}

		private function findPatches( $fromVersionPath, $toVersionpath, &$patches ) {
				$fromPatches = $this->findSqlPatches( $fromVersionPath );
				$toPatches = $this->findSqlPatches( $toVersionpath );
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

		private function findSqlPatches( $path ) {
				$patches = [];

				$files = $this->findFilesRecursively( $path );

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
