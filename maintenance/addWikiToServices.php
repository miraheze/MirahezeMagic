<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class addWikiToServices extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgServicesRepo;

		$dbw = wfGetDB( DB_MASTER );

		$res = $dbw->select(
			'cw_wikis',
			'*',
			array(),
			__METHOD__
		);

		if ( !$res || !is_object( $res ) ) {
			throw new MWException( '$res was not set to a valid array.' );
		}

		$allWikis = array();

		foreach ( $res as $row ) {
			$DBname = $row->wiki_dbname;
			$siteName = $row->wiki_sitename;
			$language = $row->wiki_language;
			$private = $row->wiki_private;
			$closed = $row->wiki_closed;
			$inactive = $row->wiki_inactive;
			$extensions = $row->wiki_extensions;
			$settings = $row->wiki_settings;

      if ( $closed !== "1" || $inactive !== "1" ) {
        $allWikis[] = "$DBname: $custom_domain ? : $miraheze_domain";
      }
		}

    file_put_contents( "$wgServicesRepo/all.dblist", implode( "\n", $allWikis ), LOCK_EX );
	}
}

$maintClass = 'addWikiToServices';
require_once RUN_MAINTENANCE_IF_MAIN;
