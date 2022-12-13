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
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$dbName = $config->get( 'DBname' );
		$wiki = new RemoteWiki( $dbName );

		if ( $wiki->isPrivate() ) {
			$manageWikiSettings = new ManageWikiSettings( $dbName );
			foreach ( $manageWikiSettings->list() as $var => $val ) {
				if (
					is_string( $val ) &&
					str_contains( $val, "static.miraheze.org/$dbName" )
				) {
					$new = preg_replace( "/((http)?(s)?(:)?\/\/)?static.miraheze.org\/$dbName/", $config->get( 'Server' ) . '/w/img_auth.php', $val );

					$this->output( "Updating {$var} for {$dbName} '{$val} => {$new}'" );

					$manageWikiSettings->modify( [ $var => $new ] );
					$manageWikiSettings->commit();
				}
			}
		}
	}
}

$maintClass = UpdatePrivateAuthUrls::class;
require_once RUN_MAINTENANCE_IF_MAIN;
