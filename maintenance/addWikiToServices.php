<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class addWikiToServices extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgCreateWikiCacheDirectory, $wgServicesRepo;

		$allWikis = [];

		// If folder does not exist, do not run the update
		if ( file_exists( $wgServicesRepo ) ) {
			$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

			$res = $dbw->select(
				'cw_wikis',
				[
					'wiki_dbname',
					'wiki_url',
				],
				[],
				__METHOD__
			);

			if ( !$res || !is_object( $res ) ) {
				throw new MWException( '$res was not set to a valid array.' );
			}

			foreach ( $res as $row ) {
				$DBname = $row->wiki_dbname;
				$mwSettings = $dbw->selectRow(
					'mw_settings',
					'*',
					[
						's_dbname' => $DBname
					]
				);

				$domain = $row->wiki_url;

				if ( !is_null( $mwSettings->s_extensions ) ) {
					$visualeditor = $this->hasExtension( 'visualeditor', $mwSettings->s_extensions );
					$flow = $this->hasExtension( 'flow', $mwSettings->s_extensions );
					// Collection installs Electron inaddition now.
					$electron = $this->hasExtension( 'collection', $mwSettings->s_extensions );
					$citoid = $this->hasExtension( 'citoid', $mwSettings->s_extensions );

					if ( $visualeditor || $flow || $electron || $citoid ) {
						$servicesvalue = !is_null( $domain ) ? str_replace('https://', '', "'" . $domain . "'") : 'true';
						$allWikis[] = "$DBname: $servicesvalue";
					}
				}
			}

			file_put_contents( "$wgServicesRepo/services.yaml", implode( "\n", $allWikis ), LOCK_EX );
		}
	}

	private function hasExtension( $extension, $extensionjson ) {
		$extensionsarray = json_decode( $extensionjson, true )
		return in_array( $extension, (array)$extensionsarray );
	}

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
