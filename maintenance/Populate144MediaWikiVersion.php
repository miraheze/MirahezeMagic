<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MirahezeFunctions;

class Populate144MediaWikiVersion extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addOption( 'dry-run', 'Performs a dry run without making any changes to the wikis.' );
	}

	public function execute() {
		$dbnames = $this->getConfig()->get( MainConfigNames::LocalDatabases );
		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );

		foreach ( $dbnames as $dbname ) {
			$oldVersion = MirahezeFunctions::getMediaWikiVersion( $dbname );

			// If it's 1.45 keep it, otherwise set 1.44
			$newVersion = ( $oldVersion === '1.45' ) ? '1.45' : '1.44';

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
					default: ( $oldVersion === '1.44' ? null : $oldVersion )
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
return Populate144MediaWikiVersion::class;
// @codeCoverageIgnoreEnd
