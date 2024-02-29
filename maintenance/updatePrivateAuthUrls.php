<?php

namespace Miraheze\MirahezeMagic\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MainConfigNames;
use Miraheze\CreateWiki\RemoteWiki;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

class UpdatePrivateAuthUrls extends Maintenance {
	public function execute() {
		$dbname = $this->getConfig()->get( MainConfigNames::DBname );

		$wiki = new RemoteWiki(
			$dbname,
			$this->getServiceContainer()->get( 'CreateWikiHookRunner' )
		);

		if ( $wiki->isPrivate() ) {
			$manageWikiSettings = new ManageWikiSettings( $dbname );
			foreach ( $manageWikiSettings->list() as $var => $val ) {
				if (
					is_string( $val ) &&
					str_contains( $val, "static.miraheze.org/$dbname" )
				) {
					$new = preg_replace( "/((http)?(s)?(:)?\/\/)?static.miraheze.org\/$dbname/", $this->getConfig()->get( MainConfigNames::Server ) . '/w/img_auth.php', $val );

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
