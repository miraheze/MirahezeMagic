<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MirahezeFunctions;

class Populate143MediaWikiVersion extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addOption( 'dry-run', 'Performs a dry run without making any changes to the wikis.' );
	}

	public function execute() {
		$dbnames = $this->getConfig()->get( MainConfigNames::LocalDatabases );
		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );

		foreach ( $dbnames as $dbname ) {
			$oldVersion = MirahezeFunctions::getMediaWikiVersion( $dbname );

			// If it's 1.44 keep it, otherwise set 1.43
			$newVersion = ( $oldVersion === '1.44' ) ? '1.44' : '1.43';

			if ( is_dir( "/srv/mediawiki/$newVersion" ) ) {
				if ( $this->hasOption( 'dry-run' ) ) {
					$this->output( "Dry run: Would update $dbname from $oldVersion to $newVersion\n" );
					continue;
				}

				$remoteWiki = $remoteWikiFactory->newInstance( $dbname );
				$remoteWiki->disableResetDatabaseLists();

				$remoteWiki->setExtraFieldData(
					'mediawiki-version',
					$newVersion,
					default: $oldVersion
				);

				$remoteWiki->commit();
				$this->output( "Updated $dbname from $oldVersion to $newVersion\n" );
			}
		}

		$dataStore = $this->getServiceContainer()->get( 'CreateWikiDataStore' );
		$dataStore->resetDatabaseLists( isNewChanges: true );
	}
}

// @codeCoverageIgnoreStart
return Populate143MediaWikiVersion::class;
// @codeCoverageIgnoreEnd
