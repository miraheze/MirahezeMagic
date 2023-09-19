<?php

/**
 * List new or updated maintenance scripts between two MediaWiki versions.
 *
 * Usage:
 *     php findPossibleUpgradeScripts.php --from-version=current_version --to-version=new_version
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

class FindPossibleUpgradeScripts extends Maintenance {
		public function __construct() {
				parent::__construct();

				$this->addDescription( 'List new or updated maintenance scripts between two MediaWiki versions.' );

				$this->addOption( 'from-version-path', 'Path to MediaWiki version to start from', true, true );
				$this->addOption( 'to-version-path', 'Path to MediaWiki version to end with', true, true );
		}

		public function execute() {
				$fromVersionPath = $this->getOption( 'from-version-path' );
				$toVersionPath = $this->getOption( 'to-version-path' );
				$scripts = [];
				$this->findScripts( $fromVersionPath, $toVersionPath, $scripts );
				foreach ( $scripts as $script ) {
						$this->output( $script . $this->findExtendedClass( $script ) . "\n" );
				}

				$this->output( "\nCount: " . count( $scripts ) . "\n" );
		}

		private function findScripts( $fromVersion, $toVersion, &$scripts ) {
				$fromScripts = $this->findMaintenanceScripts( $fromVersionPath );
				$toScripts = $this->findMaintenanceScripts( $toVersionPath );
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

		private function findMaintenanceScripts( $path ) {
				$scripts = [];

				$files1 = $this->findFilesRecursively( $path . '/**/**/maintenance/*.php' );
				$files2 = $this->findFilesRecursively( $path . '/**/**/**/maintenance/*.php' );
				$coreFiles = $this->findFilesRecursively( $path . '/maintenance/*.php' );

				foreach ( array_merge( $files1, $files2, $coreFiles ) as $file ) {
						if ( str_contains( $file, '/tests/' ) ) {
								continue;
						}

						$scripts[basename( $file )] = $file;
				}

				return $scripts;
		}

		private function isScriptUpdated( $oldScript, $newScript ) {
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

$maintClass = FindPossibleUpgradeScripts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
