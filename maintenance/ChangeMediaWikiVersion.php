<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup MirahezeMagic
 * @author Universal Omega
 * @version 2.0
 */

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MirahezeFunctions;

class ChangeMediaWikiVersion extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Change the MediaWiki version for a specific wiki or a list of wikis from a text file.' );

		$this->addOption( 'mwversion', 'Sets the wikis requested to a different MediaWiki version.', true, true );
		$this->addOption( 'file', 'Path to file where the wikinames are stored. Must be one wikidb name per line. (Optional, falls back to current dbname)', false, true );
		$this->addOption( 'regex', 'Uses a regular expression to select wikis starting with a specific pattern. Overrides the --file option.' );
		$this->addOption( 'dry-run', 'Performs a dry run without making any changes to the wikis.' );

		// State options
		$this->addOption( 'active', 'Only change MediaWiki version on active wikis.' );
		$this->addOption( 'closed', 'Only change MediaWiki version on closed wikis.' );
		$this->addOption( 'deleted', 'Only change MediaWiki version on deleted wikis.' );
		$this->addOption( 'inactive', 'Only change MediaWiki version on inactive wikis.' );
	}

	public function execute() {
		$dbnames = [];
		if ( $this->hasOption( 'regex' ) ) {
			$pattern = $this->getOption( 'regex' );
			$dbnames = $this->getWikiDbNamesByRegex( $pattern );
		} elseif ( $this->hasOption( 'file' ) ) {
			$dbnames = file( $this->getOption( 'file' ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( !$dbnames ) {
				$this->fatalError( 'Unable to read file, exiting' );
			}
		} elseif ( $this->hasOption( 'active' ) || $this->hasOption( 'closed' ) || $this->hasOption( 'deleted' ) || $this->hasOption( 'inactive' ) ) {
			$dbnames = $this->getConfig()->get( MainConfigNames::LocalDatabases );
		} else {
			$dbnames[] = $this->getConfig()->get( MainConfigNames::DBname );
		}

		$remoteWikiFactory = $this->getServiceContainer()->get( 'RemoteWikiFactory' );

		foreach ( $dbnames as $dbname ) {
			$oldVersion = MirahezeFunctions::getMediaWikiVersion( $dbname );
			$newVersion = $this->getOption( 'mwversion' );

			if ( is_dir( "/srv/mediawiki/$newVersion" ) ) {
				$remoteWiki = $remoteWikiFactory->newInstance( $dbname );
				$remoteWiki->disableResetDatabaseLists();
				if ( $this->hasOption( 'active' ) && ( $remoteWiki->isClosed() || $remoteWiki->isDeleted() || $remoteWiki->isInactive() ) ) {
					continue;
				}

				if ( $this->hasOption( 'closed' ) && !$remoteWiki->isClosed() ) {
					continue;
				}

				if ( $this->hasOption( 'deleted' ) && !$remoteWiki->isDeleted() ) {
					continue;
				}

				if ( $this->hasOption( 'inactive' ) && !$remoteWiki->isInactive() ) {
					continue;
				}

				if ( $this->hasOption( 'dry-run' ) ) {
					$this->output( "Dry run: Would upgrade $dbname from $oldVersion to $newVersion\n" );
					continue;
				}

				$remoteWiki->setExtraFieldData(
					'mediawiki-version', $newVersion, default: $oldVersion
				);

				$remoteWiki->commit();
				$this->output( "Upgraded $dbname from $oldVersion to $newVersion\n" );
			}
		}

		$dataStore = $this->getServiceContainer()->get( 'CreateWikiDataStore' );
		$dataStore->resetDatabaseLists( isNewChanges: true );
	}

	private function getWikiDbNamesByRegex( string $pattern ): array {
		$allDbNames = $this->getConfig()->get( MainConfigNames::LocalDatabases );

		$matchingDbNames = [];
		foreach ( $allDbNames as $dbName ) {
			if ( preg_match( $pattern, $dbName ) ) {
				$matchingDbNames[] = $dbName;
			}
		}

		return $matchingDbNames;
	}
}

// @codeCoverageIgnoreStart
return ChangeMediaWikiVersion::class;
// @codeCoverageIgnoreEnd
