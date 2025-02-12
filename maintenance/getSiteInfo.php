<?php

namespace Miraheze\MirahezeMagic\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MainConfigNames;

class GetSiteInfo extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Returns information about a wiki like sitename, URL, etc.' );
	}

	public function execute() {
		$language = $this->getConfig()->get( MainConfigNames::LanguageCode );
		$license = $this->getConfig()->get( MainConfigNames::RightsText );
		$sitename = $this->getConfig()->get( MainConfigNames::Sitename );
		$url = $this->getConfig()->get( MainConfigNames::Server );

		$this->output( "Sitename: $sitename\nURL: $url\nLanguage: $language\nLicense: $license\n" );
	}
}

$maintClass = GetSiteInfo::class;
require_once RUN_MAINTENANCE_IF_MAIN;
