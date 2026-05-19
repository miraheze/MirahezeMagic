<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class PopulateMediaWikiVersion extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populate the mediawiki-version field on all current wikis so that new wikis can be upgraded to a new version.' );

		$this->addOption( 'dry-run', 'Performs a dry run without making any changes.' );
		$this->addOption( 'new-version', 'The MediaWiki version to keep', true );
		$this->addOption( 'old-version', 'The MediaWiki version to set', true );
	}

	public function execute() {
		$newVersion = $this->getOption( 'new-version' );
		$oldVersion = $this->getOption( 'old-version' );

		if ( !is_dir( "/srv/mediawiki/$newVersion" ) ) {
			$this->fatalError( "Folder /srv/mediawiki/$newVersion does not exist. Aborting." );
		}

		$dbnames = $this->getConfig()->get( MainConfigNames::LocalDatabases );
		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );

		foreach ( $dbnames as $dbname ) {
			$remoteWiki = $remoteWikiFactory->newInstance( $dbname );
			$remoteWiki->disableResetDatabaseLists();

			$currentVersion = $remoteWiki->getExtraFieldData( 'mediawiki-version', default: null );
			if ( $currentVersion === $oldVersion || $currentVersion === $newVersion ) {
				continue;
			}

			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( "Dry run: Would set mediawiki-version for $dbname to $oldVersion\n" );
				continue;
			}

			$remoteWiki->setExtraFieldData(
				'mediawiki-version', $oldVersion, default: null
			);

			$remoteWiki->commit();
			$this->output( "Set mediawiki-version for $dbname to $oldVersion\n" );
		}

		$dataStore = $this->getServiceContainer()->get( 'CreateWikiDataStore' );
		$dataStore->resetDatabaseLists( isNewChanges: true );
	}
}

// @codeCoverageIgnoreStart
return PopulateMediaWikiVersion::class;
// @codeCoverageIgnoreEnd
