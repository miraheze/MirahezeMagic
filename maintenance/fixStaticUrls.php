<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

class FixStaticUrls extends Maintenance {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$dbName = $config->get( 'DBname' );

		$manageWikiSettings = new ManageWikiSettings( $dbName );
		foreach ( $manageWikiSettings->list() as $var => $val ) {
			if (
				is_string( $val ) &&
				str_contains( $val, 'static-new.miraheze.org' )
			) {
				$manageWikiSettings->modify( [ $var => str_replace( 'static-new.miraheze.org', 'static.miraheze.org', $val ) ] );
				$manageWikiSettings->commit();
			}
		}
	}
}

$maintClass = FixStaticUrls::class;
require_once RUN_MAINTENANCE_IF_MAIN;
