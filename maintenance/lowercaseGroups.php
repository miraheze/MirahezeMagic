<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class lowercaseGroups extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );
		
		$dbw_wiki = wfGetDB( DB_MASTER );

		$res = $dbw->select(
			'mw_permissions',
			[
				'perm_dbanme',
				'perm_group',
			],
			[],
			__METHOD__
		);

		if ( !$res || !is_object( $res ) ) {
		    throw new MWException( '$res was not set to a valid array.' );
		}

		foreach ( $res as $row ) {
			if ( !$row->perm_dbanme || !$row->perm_group ) {
				continue;
			}
			$dbw->update( 'mw_permissions',
				[
					'perm_group' => strtolower( $row->perm_group )
				],
				[
					'perm_dbname' => $row->perm_dbanme,
					'perm_group' => $row->perm_group
				],
				__METHOD__
			);

			$dbw_wiki = wfGetDB( DB_MASTER, [], $row->perm_dbanme );
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
