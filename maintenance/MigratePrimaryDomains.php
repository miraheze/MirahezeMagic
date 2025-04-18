<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MirahezeFunctions;

class MigratePrimaryDomains extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'MirahezeMagic' );
	}

	public function execute(): void {
		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );

		foreach ( $this->getConfig()->get( MainConfigNames::LocalDatabases ) as $wiki ) {
			$remoteWiki = $remoteWikiFactory->newInstance( $wiki );
			$remoteWiki->disableResetDatabaseLists();

			$primaryDomain = MirahezeFunctions::getPrimaryDomain( $wiki );

			if (
				$primaryDomain &&
				$primaryDomain !== 'miraheze.org' &&
				$primaryDomain !== 'mirabeta.org'
			) {
				$this->output( "Migrating primary domain for $wiki\n" );

				$remoteWiki->setExtraFieldData( 'primary-domain', $primaryDomain, default: null );
				$remoteWiki->commit();
			}
		}

		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
 		$dataFactory = $this->getServiceContainer()->get( 'CreateWikiDataFactory' );
 		$data = $dataFactory->newInstance( $databaseUtils->getCentralWikiID() );
 		$data->resetDatabaseLists( isNewChanges: true );
	}
}

// @codeCoverageIgnoreStart
return MigrateDescriptions::class;
// @codeCoverageIgnoreEnd
