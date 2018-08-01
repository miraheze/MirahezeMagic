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
				$DBname = substr( $wiki[0], 0, -4 );
				$domain = $this->getSettingsValue( 'wgServer', $wiki[4] );

				if ( !is_null( $wiki[3] ) ) {
					$visualeditor = $this->hasExtension( 'visualeditor', $wiki[3] );
					$flow = $this->hasExtension( 'flow', $wiki[3] );

					if ( $visualeditor || $flow ) {
						$servicesvalue = $domain ? str_replace('https://', '', "'" . $domain . "'") : 'true';
						$allWikis[] = "$DBname: $servicesvalue";
					}
				}
				
			}

			file_put_contents( "$wgServicesRepo/services.yaml", implode( "\n", $allWikis ), LOCK_EX );
		}
	}

	/**
	 * Similar to CreateWiki RemoteWiki function, just this reads from a file.
	 */
	private function hasExtension( $ext, $listExt ) {
		$extensionsarray = explode( ",", $listExt );

		return in_array( $ext, $extensionsarray );
	}

	/**
	 * Similar to CreateWiki RemoteWiki function, just this reads from a file.
	 */
	private function getSettingsValue( $setting, $settingsjson ) {
		$settingsarray = json_decode( $settingsjson, true );

		if ( isset( $settingsarray[$setting] ) ) {
			return $settingsarray[$setting];
		}

		return null;
	}
}

$maintClass = 'addWikiToServices';
require_once RUN_MAINTENANCE_IF_MAIN;
