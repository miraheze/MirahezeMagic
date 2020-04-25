<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class GenerateManageWikiBackup extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addArg( 'ext', 'Backups mw_* tables', true );
	}

	public function execute() {
		global $wgDBname, $wgCreateWikiDatabase, $wgDataDumpDirectory, $wgManageWikiPermissionsAdditionalRights,
			$wgManageWikiPermissionsAdditionalAddGroups, $wgManageWikiPermissionsAdditionalRemoveGroups;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$buildArray = [];

		$nsObjects = $dbw->select(
			'mw_namespaces',
			'*',
			[ 'ns_dbname' => $wgDBname ]
		);

		$permObjects = $dbw->select(
			'mw_permissions',
			'*',
			[ 'perm_dbname' => $wgDBname ]
		);

		$settingsObjects = $dbw->selectRow(
			'mw_settings',
			'*',
			[ 's_dbname' => $wgDBname ],
			__METHOD__
		);

        if ( $settingsObjects != null ) {
			$buildArray['extensions'] = json_decode( $settingsObjects->s_extensions );
			$buildArray['settings'] = json_decode( $settingsObjects->s_settings );
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
			$addPerms =[];

			foreach ( ( $wgManageWikiPermissionsAdditionalRights[$perm->perm_group] ?? [] ) as $right => $bool ) {
				if ( $bool ) {
					$addPerms[] = $right;
				}
			}

			$buildArray['permissions'][$perm->perm_group] = [
				'permissions' => array_merge( json_decode( $perm->perm_permissions, true ), $addPerms ),
				'addgroups' => array_merge( json_decode( $perm->perm_addgroups, true ), $wgManageWikiPermissionsAdditionalAddGroups[$perm->perm_group] ?? [] ),
				'removegroups' => array_merge( json_decode( $perm->perm_removegroups, true ), $wgManageWikiPermissionsAdditionalRemoveGroups[$perm->perm_group] ?? [] ),
				'addself' => json_decode( $perm->perm_addgroupstoself, true ),
				'removeself' => json_decode( $perm->perm_removegroupsfromself, true ),
				'autopromote' => json_decode( $perm->perm_autopromote, true )
			];
		}
		
		file_put_contents( "{$wgDataDumpDirectory}/managewiki_backups.json", json_encode( $buildArray, JSON_PRETTY_PRINT ) );
	}
}

$maintClass = 'GenerateManageWikiBackup';
require_once RUN_MAINTENANCE_IF_MAIN;
