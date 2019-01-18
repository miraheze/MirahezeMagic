<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class InsertNamespaces extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	function execute() {
		global $wgCreateWikiDatabase;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$row = $dbw->select(
			'mw_namespaces',
			[
				'*',
			],
			[
				'ns_dbname' => 'default',
			],
			__METHOD__
		);



		$this->insertNamespace( $dbw, $row);
	}
	
	public function insertNamespace( $dbw, $row ) {
		global $wgDBname;

		$resObj = $dbw->select(
			'mw_namespaces',
			[
				'*',
			],
			[
				'ns_dbname' => $wgDBname,
				'ns_namespace_id' => $row->ns_namespace_id,
				'ns_namespace_name' => $row->ns_namespace_name,
				'ns_searchable' => $row->ns_searchable,
				'ns_subpages' => $row->ns_subpages,
				'ns_content' => $row->ns_content,
				'ns_protection' => $row->ns_protection,
				'ns_aliases' => $row->ns_aliases,
				'ns_core' => $row->ns_core,
			],
			__METHOD__
		);

		if ( !$resObj || !is_object( $resObj ) ) {
			$dbw->insert(
				'mw_namespaces',
				[
					'ns_dbname' => $wgDBname,
					'ns_namespace_id' => $row->ns_namespace_id,
					'ns_namespace_name' => $row->ns_namespace_name,
					'ns_searchable' => $row->ns_searchable,
					'ns_subpages' => $row->ns_subpages,
					'ns_content' => $row->ns_content,
					'ns_protection' => $row->ns_protection,
					'ns_aliases' => $row->ns_aliases,
					'ns_core' => $row->ns_core,
				],
				__METHOD__
			);
		}
	}
}

$maintClass = 'InsertNamespaces';
require_once RUN_MAINTENANCE_IF_MAIN;
