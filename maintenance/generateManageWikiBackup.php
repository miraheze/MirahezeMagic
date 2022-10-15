<?php

/**
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
 * @author Paladox
 * @version 1.0
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class GenerateManageWikiBackup extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'filename', 'Filename to dump json to.', true, true );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$dbName = $config->get( 'DBname' );
		$dbw = $this->getDB( DB_PRIMARY, [], $config->get( 'CreateWikiDatabase' ) );

		$buildArray = [];

		$nsObjects = $dbw->select(
			'mw_namespaces',
			'*',
			[ 'ns_dbname' => $dbName ]
		);

		$permObjects = $dbw->select(
			'mw_permissions',
			'*',
			[ 'perm_dbname' => $dbName ]
		);

		$settingsObjects = $dbw->selectRow(
			'mw_settings',
			'*',
			[ 's_dbname' => $dbName ],
			__METHOD__
		);

		if ( $settingsObjects != null ) {
			$buildArray['extensions'] = json_decode( $settingsObjects->s_extensions );

			$buildArray['settings'] = json_decode( $settingsObjects->s_settings, true );

			foreach ( $config->get( 'ManageWikiSettings' ) as $setting => $options ) {
				if ( isset( $options['requires']['visibility']['permissions'] ) ) {
					unset( $buildArray['settings'][$setting] );
				}
			}
		}

		foreach ( $nsObjects as $ns ) {
			$buildArray['namespaces'][$ns->ns_namespace_name] = [
				'id' => $ns->ns_namespace_id,
				'core' => (bool)$ns->ns_core,
				'searchable' => (bool)$ns->ns_searchable,
				'subpages' => (bool)$ns->ns_subpages,
				'content' => (bool)$ns->ns_content,
				'contentmodel' => $ns->ns_content_model,
				'protection' => ( (bool)$ns->ns_protection ) ? $ns->ns_protection : false,
				'aliases' => json_decode( $ns->ns_aliases, true ),
				'additional' => json_decode( $ns->ns_additional, true )
			];
		}

		foreach ( $permObjects as $perm ) {
			$addPerms = [];

			foreach ( ( $config->get( 'ManageWikiPermissionsAdditionalRights' )[$perm->perm_group] ?? [] ) as $right => $bool ) {
				if ( $bool ) {
					$addPerms[] = $right;
				}
			}

			$buildArray['permissions'][$perm->perm_group] = [
				'permissions' => array_merge( json_decode( $perm->perm_permissions, true ), $addPerms ),
				'addgroups' => array_merge( json_decode( $perm->perm_addgroups, true ), $config->get( 'ManageWikiPermissionsAdditionalAddGroups' )[$perm->perm_group] ?? [] ),
				'removegroups' => array_merge( json_decode( $perm->perm_removegroups, true ), $config->get( 'ManageWikiPermissionsAdditionalRemoveGroups' )[$perm->perm_group] ?? [] ),
				'addself' => json_decode( $perm->perm_addgroupstoself, true ),
				'removeself' => json_decode( $perm->perm_removegroupsfromself, true ),
				'autopromote' => json_decode( $perm->perm_autopromote, true )
			];
		}

		$backend = DataDump::getBackend();
		$backend->prepare( [ 'dir' => $backend->getRootStoragePath() . '/dumps-backup/' ] );

		$backend->quickCreate( [
			'dst' => $backend->getRootStoragePath() . '/dumps-backup/' . $this->getOption( 'filename' ),
			'content' => json_encode( $buildArray, JSON_PRETTY_PRINT ),
			'overwrite' => true,
		] );
	}
}

$maintClass = 'GenerateManageWikiBackup';
require_once RUN_MAINTENANCE_IF_MAIN;
