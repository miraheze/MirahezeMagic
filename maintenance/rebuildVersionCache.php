<?php

/**
 * Rebuild the version cache.
 *
 * Usage:
 *    php rebuildVersionCache.php
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
 *
 * @author Universal Omega
 * @version 2.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

/**
 * Maintenance script to rebuild the version cache.
 *
 * @ingroup Maintenance
 * @see GitInfo
 * @see SpecialVersion
 */
class RebuildVersionCache extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Rebuild the version cache' );
		$this->addOption( 'save-gitinfo', 'Save gitinfo.json files' );
	}

	public function execute() {
		$hashConfig = new HashConfig();

		$hashConfig->set( 'ShellRestrictionMethod', false );

		$baseDirectory = MW_INSTALL_PATH;
		$gitInfo = new GitInfo( $baseDirectory, false );

		$gitInfo->precomputeValues();

		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$coreId = $gitInfo->getHeadSHA1() ?: '';

		$queue = array_fill_keys( array_merge(
				glob( $baseDirectory . '/extensions/*/extension*.json' ),
				glob( $baseDirectory . '/skins/*/skin.json' )
			),
		true );

		$processor = new ExtensionProcessor();

		foreach ( $queue as $path => $mtime ) {
			$json = file_get_contents( $path );
			$info = json_decode( $json, true );
			$version = $info['manifest_version'];

			$processor->extractInfo( $path, $info, $version );
		}

		$data = $processor->getExtractedInfo();

		$extensionCredits = array_merge( $data['credits'], array_values(
				array_merge( ...array_values( $this->getConfig()->get( 'ExtensionCredits' ) ) )
			)
		);

		foreach ( $extensionCredits as $extension => $extensionData ) {
			if ( isset( $extensionData['path'] ) ) {
				$extensionDirectory = dirname( $extensionData['path'] );
				$extensionPath = str_replace( '/srv/mediawiki/w', $baseDirectory, $extensionDirectory );

				$gitInfo = new GitInfo( $extensionPath, false );

				if ( $this->hasOption( 'save-gitinfo' ) ) {
					$gitInfo->precomputeValues();
				}

				$memcKey = $cache->makeKey(
					'specialversion-ext-version-text', str_replace( $baseDirectory, '/srv/mediawiki/w', $extensionData['path'] ), $coreId
				);

				$cache->delete( $memcKey );
			}
		}
	}
}

$maintClass = RebuildVersionCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
