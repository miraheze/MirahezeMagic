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
 */

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

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
		global $IP;

		$gitInfo = new GitInfo( $IP, false );
		$gitInfo->precomputeValues();

		$cache = ObjectCache::getInstance( CACHE_ANYTHING );
		$coreId = $gitInfo->getHeadSHA1() ?: '';

		$extensionCredits = $this->getConfig()->get( 'ExtensionCredits' );
		foreach ( $extensionCredits as $type => $extensions ) {
			foreach ( $extensions as $extension ) {
				if ( isset( $extension['path'] ) ) {
					$extensionPath = dirname( $extension['path'] );
					$gitInfo = new GitInfo( $extensionPath, false );
					if ( $this->hasOption( 'save-gitinfo' ) ) {
						$gitInfo->precomputeValues();
					}
					$memcKey = $cache->makeKey(
						'specialversion-ext-version-text', $extension['path'], $coreId
					);
					$cache->delete( $memcKey );
				}
			}
		}
	}
}

$maintClass = RebuildVersionCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
