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

		if ( ManageWiki::checkSetup( 'namespaces' ) ) {
			$this->fatalError( 'Disable ManageWiki Namespaces on this wiki.' );
		}

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$res = $dbw->select(
			'mw_namespaces',
			[
				'*',
			],
			[
				'ns_dbname' => 'default',
			],
			__METHOD__
		);



		$this->insertNamespace( $dbw, $res);
	}
	
	public function insertNamespace( $dbw, $res ) {
		global $wgDBname;

		$resObj = $dbw->select(
			'mw_namespaces',
			[
				'*',
			],
			[
				'ns_dbname' => $wgDBname,
				'ns_namespace_id' => $res->ns_namespace_id,
				'ns_namespace_name' => $res->ns_namespace_name,
				'ns_searchable' => $res->ns_searchable,
				'ns_subpages' => $res->ns_subpages,
				'ns_content' => $res->ns_content,
				'ns_protection' => $res->ns_protection,
				'ns_aliases' => $res->ns_aliases,
				'ns_core' => $res->ns_core,
			],
			__METHOD__
		);

		if ( !$resObj || !is_object( $resObj ) ) {
			$dbw->insert(
				'mw_namespaces',
				[
					'ns_dbname' => $wgDBname,
					'ns_namespace_id' => $res->ns_namespace_id,
					'ns_namespace_name' => $res->ns_namespace_name,
					'ns_searchable' => $res->ns_searchable,
					'ns_subpages' => $res->ns_subpages,
					'ns_content' => $res->ns_content,
					'ns_protection' => $res->ns_protection,
					'ns_aliases' => $res->ns_aliases,
					'ns_core' => $res->ns_core,
				],
				__METHOD__
			);
		}
	}
}

$maintClass = 'InsertNamespaces';
require_once RUN_MAINTENANCE_IF_MAIN;
