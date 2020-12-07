<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class addWikiToServices extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgCreateWikiDatabase, $wgServicesRepo;

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
				$domain = $row->wiki_url;

				$mwSettings = $dbw->selectRow(
					'mw_settings',
					'*',
					[
						's_dbname' => $DBname
					]
				);

				if ( !isset( $mwSettings->s_extensions ) ) {
					continue;
				}

				if ( !is_null( $mwSettings->s_extensions ) ) {
					$extensionsArray = json_decode( $mwSettings->s_extensions, true );
					$linter = $this->hasExtension( 'linter', $extensionsArray );
					// Collection installs Electron inaddition now.
					$electron = $this->hasExtension( 'collection', $extensionsArray );
					$citoid = $this->hasExtension( 'citoid', $extensionsArray );

					if ( $electron || $citoid || $linter ) {
						$servicesvalue = !empty( $domain ) ? str_replace('https://', '', "'" . $domain . "'") : 'true';
						// Remove wiki from the end of the name.
						$DBname = substr( $DBname, 0, -4 );
						$allWikis[] = "$DBname: $servicesvalue";
					}
				}
			}

			file_put_contents( "$wgServicesRepo/services.yaml", implode( "\n", $allWikis ), LOCK_EX );
		}
	}

	private function hasExtension( $extension, $extensionsarray ) {
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
