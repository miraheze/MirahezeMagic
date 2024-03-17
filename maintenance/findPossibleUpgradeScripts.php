<?php

namespace Miraheze\MirahezeMagic\Maintenance;

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

class FindPossibleUpgradeScripts extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new or updated maintenance scripts between two MediaWiki versions.' );

		$this->addOption( 'from-version', 'MediaWiki version to start from', true, true );
		$this->addOption( 'to-version', 'MediaWiki version to end with', true, true );
	}

	public function execute() {
		$fromVersion = $this->getOption( 'from-version' );
		$toVersion = $this->getOption( 'to-version' );
		$scripts = [];
		$this->findScripts( $fromVersion, $toVersion, $scripts );
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

		$files1 = $this->findFilesRecursively( '/srv/mediawiki-staging/' . $version . '/**/**/maintenance/*.php' );
		$files2 = $this->findFilesRecursively( '/srv/mediawiki-staging/' . $version . '/**/**/**/maintenance/*.php' );
		$coreFiles = $this->findFilesRecursively( '/srv/mediawiki-staging/' . $version . '/maintenance/*.php' );

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
