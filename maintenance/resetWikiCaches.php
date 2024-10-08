<?php

namespace Miraheze\MirahezeMagic\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MainConfigNames;

class ResetWikiCaches extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Resets ManageWiki cache.' );
	}

	public function execute() {
		$dataFactory = $this->getServiceContainer()->get( 'CreateWikiDataFactory' );
		$data = $dataFactory->newInstance( $this->getConfig()->get( MainConfigNames::DBname ) );
		$data->resetWikiData( isNewChanges: true );

		usleep( 20000 );
	}
}

$maintClass = ResetWikiCaches::class;
require_once RUN_MAINTENANCE_IF_MAIN;
