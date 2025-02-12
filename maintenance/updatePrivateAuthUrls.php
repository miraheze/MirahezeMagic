<?php

namespace Miraheze\MirahezeMagic\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MainConfigNames;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

class UpdatePrivateAuthUrls extends Maintenance {

	public function execute() {
		$dbname = $this->getConfig()->get( MainConfigNames::DBname );

		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );
		$remoteWiki = $remoteWikiFactory->newInstance( $dbname );

		if ( $remoteWiki->isPrivate() ) {
			$manageWikiSettings = new ManageWikiSettings( $dbname );
			foreach ( $manageWikiSettings->list() as $var => $val ) {
				if (
					is_string( $val ) &&
					str_contains( $val, "static.wikitide.net/$dbname" )
				) {
					$new = preg_replace( "/((http)?(s)?(:)?\/\/)?static.wikitide.net\/$dbname/", $this->getConfig()->get( MainConfigNames::Server ) . '/w/img_auth.php', $val );

					$this->output( "Updating {$var} for {$dbname} '{$val} => {$new}'\n" );

					$manageWikiSettings->modify( [ $var => $new ] );
					$manageWikiSettings->commit();
				}
			}
		}
	}
}

$maintClass = UpdatePrivateAuthUrls::class;
require_once RUN_MAINTENANCE_IF_MAIN;
