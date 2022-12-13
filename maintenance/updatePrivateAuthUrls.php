<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

class UpdatePrivateAuthUrls extends Maintenance {
	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'managewiki' );
		$dbName = $config->get( 'DBname' );
		$wiki = new RemoteWiki( $dbName );
		$manageWikiSettings = new ManageWikiSettings( $dbName );

		if ( $wiki->isPrivate() ) {
			foreach ( $config->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					is_string( $manageWikiSettings->list( $var ) ) &&
					str_contains( $manageWikiSettings->list( $var ), "static.miraheze.org/$dbName" )
				) {
					$manageWikiSettings->modify( [ $var => str_replace( "static.miraheze.org/$dbName", '/w/img_auth', $manageWikiSettings->list( $var ) ] );
					$manageWikiSettings->commit();
				}
			}
		}
	}
}

$maintClass = UpdatePrivateAuthUrls::class;
require_once RUN_MAINTENANCE_IF_MAIN;
