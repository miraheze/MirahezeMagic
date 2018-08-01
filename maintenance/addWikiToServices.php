<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class addWikiToServices extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgServicesRepo;

		/*$dbw = wfGetDB( DB_MASTER );

		$res = $dbw->select(
			'cw_wikis',
			'*',
			array(),
			__METHOD__
		);

		if ( !$res || !is_object( $res ) ) {
			throw new MWException( '$res was not set to a valid array.' );
		}*/

		$allWikis = array();

		if ( file_exists("$wgServicesRepo/services.yaml") ) {
			//foreach ( explode("\n", file_get_contents('/srv/mediawiki/dblist/all.dblist')) as $wiki ) {
			        $wikis = file( '/srv/mediawiki/dblist/all.dblist' );
				foreach ( $wikis as $wiki ) {
					$wiki = explode( '|', $wiki);
				        $DBname = $wiki[0];
				        $remote = RemoteWiki::newFromName( $wiki[0] )->getSettingsValue( 'wgServer' );
				        $flow = RemoteWiki::newFromName( $wiki[0] )->hasExtension( 'flow' );
				        $visualEditor = RemoteWiki::newFromName( $wiki[0] )->hasExtension( 'visualeditor' );
				        $this->output($flow);
				        $this->output($visualEditor);
				        if ( $visualEditor || $flow ) {
					        $custom_domain = $remote ? str_replace('https://', '', "'" . $remote . "'") : 'true';

					        $allWikis[] = "$DBname: $custom_domain";
				        }
				
			}

			file_put_contents( "$wgServicesRepo/services.yaml", implode( "\n", $allWikis ), LOCK_EX );
		}
	}
}

$maintClass = 'addWikiToServices';
require_once RUN_MAINTENANCE_IF_MAIN;
