<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

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

// @codeCoverageIgnoreStart
return ResetWikiCaches::class;
// @codeCoverageIgnoreEnd
