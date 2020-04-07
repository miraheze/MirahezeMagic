<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class spScript extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgCreateWikiDatabase, $wgDBname;
		
		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$res = $dbw->select(
			'cw_wikis',
			[
				'wiki_dbname',
				'wiki_settings',
			],
			[
				'wiki_dbame' => $wgDBname
			],
		);

		foreach ( $res as $row ) {
			$settings = json_decode( $row->wiki_settings, true );

			if ( $settings && isset( $settings['wgUserProfileDisplay'] ) && $settings['wgUserProfileDisplay'] ) {
				if ( !isset( $settings['wgUserProfileDisplay']['activity'] ) ) {
					$settings['wgUserProfileDisplay']['activity'] = false;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['articles'] ) ) {
					$settings['wgUserProfileDisplay']['articles'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['avatar'] ) ) {
					$settings['wgUserProfileDisplay']['avatar'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['awards'] ) ) {
					$settings['wgUserProfileDisplay']['awards'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['board'] ) ) {
					$settings['wgUserProfileDisplay']['board'] = false;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['custom'] ) ) {
					$settings['wgUserProfileDisplay']['custom'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['foes'] ) ) {
					$settings['wgUserProfileDisplay']['foes'] = false;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['friends'] ) ) {
					$settings['wgUserProfileDisplay']['friends'] = false;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['games'] ) ) {
					$settings['wgUserProfileDisplay']['games'] = false;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['gifts'] ) ) {
					$settings['wgUserProfileDisplay']['gifts'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['interests'] ) ) {
					$settings['wgUserProfileDisplay']['interests'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['personal'] ) ) {
					$settings['wgUserProfileDisplay']['personal'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['profile'] ) ) {
					$settings['wgUserProfileDisplay']['profile'] = true;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['stats'] ) ) {
					$settings['wgUserProfileDisplay']['stats'] = false;
				}

				if ( !isset( $settings['wgUserProfileDisplay']['userboxes'] ) ) {
					$settings['wgUserProfileDisplay']['userboxes'] = false;
				}

				$dbw->update(
					'cw_wikis',
					[
						'wiki_settings' => json_encode( $settings ),
					],
					[
						'wiki_dbname' => $wgDBname
					],
					__METHOD__
				);

				$dbw->update(
					'mw_settings',
					[
						's_settings' => json_encode( $settings ),
					],
					[
						's_dbname' => $wgDBname
					],
					__METHOD__
				);
			}
		}
	}
}

$maintClass = 'spScript';
require_once RUN_MAINTENANCE_IF_MAIN;
