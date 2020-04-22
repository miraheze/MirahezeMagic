<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class lowercaseGroups extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$res = $dbw->select(
			'mw_permissions',
			[
				'perm_dbname',
				'perm_group',
			],
			[],
			__METHOD__
		);

		if ( !$res || !is_object( $res ) ) {
		    throw new MWException( '$res was not set to a valid array.' );
		}

		foreach ( $res as $row ) {
			if ( !isset( $row->perm_dbname ) || !isset( $row->perm_group ) ) {
				continue;
			}
			$dbw->update( 'mw_permissions',
				[
					'perm_group' => strtolower( $row->perm_group )
				],
				[
					'perm_dbname' => $row->perm_dbname,
					'perm_group' => $row->perm_group
				],
				__METHOD__
			);

			$dbw_wiki = wfGetDB( DB_MASTER, [], $row->perm_dbname );
			$dbw_wiki->update( 'user_groups',
				[
					'ug_group' => strtolower( $row->perm_group )
				],
				[
					'ug_group' => $row->perm_group
				],
				__METHOD__
			);

			$dbw_wiki->update( 'user_former_groups',
				[
					'ufg_group' => strtolower( $row->perm_group )
				],
				[
					'ufg_group' => $row->perm_group
				],
				__METHOD__
			);
		}
	}
}

$maintClass = 'lowercaseGroups';
require_once RUN_MAINTENANCE_IF_MAIN;
