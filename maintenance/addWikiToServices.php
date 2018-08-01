<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class addWikiToServices extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgServicesRepo;

		$allWikis = array();

		if ( file_exists("$wgServicesRepo/services.yaml") ) {
			$wikis = file( '/srv/mediawiki/dblist/all.dblist' );
			foreach ( $wikis as $wiki ) {
				$wiki = explode( '|', $wiki);
					$DBname = $wiki[0];
					$remote = RemoteWiki::newFromName( $wiki[0] )->getSettingsValue( 'wgServer' );
					$flow = RemoteWiki::newFromName( $wiki[0] )->hasExtension( 'flow' );
				$visualEditor = RemoteWiki::newFromName( $wiki[0] )->hasExtension( 'visualeditor' );
				if ( !is_null( $wiki[3] ) ) {
					$visualEditor = $this->hasExt( 'visualeditor', $wiki[3] );
					$flow = $this->hasExt( 'flow', $wiki[3] );
					if ( $visualEditor || $flow ) {
						$custom_domain = $remote ? str_replace('https://', '', "'" . $remote . "'") : 'true';

							$allWikis[] = "$DBname: $custom_domain";
						}
				}
				
			}

			file_put_contents( "$wgServicesRepo/services.yaml", implode( "\n", $allWikis ), LOCK_EX );
		}
	}

	public function hasExt( $ext, $listExt ) {
		$extensionsarray = explode( ",", $listExt );

		return in_array( $ext, $extensionsarray );
	}
}

$maintClass = 'addWikiToServices';
require_once RUN_MAINTENANCE_IF_MAIN;
