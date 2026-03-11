<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MirahezeFunctions;

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
			$currentVersion = MirahezeFunctions::getMediaWikiVersion( $dbname );
			if ( $currentVersion === $newVersion ) {
				continue;
			}

			if ( $this->hasOption( 'dry-run' ) ) {
				$this->output( "Dry run: Would update $dbname from $currentVersion to $newVersion\n" );
				continue;
			}

			$remoteWiki = $remoteWikiFactory->newInstance( $dbname );
			$remoteWiki->disableResetDatabaseLists();

			$remoteWiki->setExtraFieldData(
				'mediawiki-version',
				$oldVersion,
				default: null
			);

			$remoteWiki->commit();
			$this->output( "Updated $dbname from $currentVersion to $newVersion\n" );
		}

		$dataStore = $this->getServiceContainer()->get( 'CreateWikiDataStore' );
		$dataStore->resetDatabaseLists( isNewChanges: true );
	}
}

// @codeCoverageIgnoreStart
return PopulateMediaWikiVersion::class;
// @codeCoverageIgnoreEnd
