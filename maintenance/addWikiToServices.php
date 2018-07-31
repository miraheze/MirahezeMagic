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

		if ( file_exists("$wgServicesRepo/services.yaml") ) {
			foreach ( $res as $row ) {
				global $wgMirahezeServicesExtensions;
				foreach ( $wgMirahezeServicesExtensions as $ext ) {
					if ( ExtensionRegistry::getInstance()->isLoaded( $ext ) ) {
						$DBname = $row->wiki_dbname;
						$remote = RemoteWiki::newFromName( $DBname )->getSettingsValue( 'wgServer' );
						$custom_domain = $remote ? str_replace('https://', '', $remote) : true;

						$allWikis[] = "$DBname: $custom_domain";
					}
				}
			}

			file_put_contents( "$wgServicesRepo/services.yaml", implode( "\n", $allWikis ), LOCK_EX );
		}
	}
}

$maintClass = 'addWikiToServices';
require_once RUN_MAINTENANCE_IF_MAIN;
