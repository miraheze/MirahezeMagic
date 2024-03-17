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
 * @version 1.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MainConfigNames;
use Miraheze\CreateWiki\RemoteWiki;
use MirahezeFunctions;

class ChangeMediaWikiVersion extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Change the MediaWiki version for a specific wiki or a list of wikis from a text file.' );

		$this->addOption( 'mwversion', 'Sets the wikis requested to a different MediaWiki version.', true, true );
		$this->addOption( 'file', 'Path to file where the wikinames are stored. Must be one wikidb name per line. (Optional, falls back to current dbname)', false, true );
		$this->addOption( 'regex', 'Uses a regular expression to select wikis starting with a specific pattern. Overrides the --file option.' );
		$this->addOption( 'dry-run', 'Performs a dry run without making any changes to the wikis.' );
	}

	public function execute() {
		$dbnames = [];

		if ( (bool)$this->getOption( 'regex' ) ) {
			$pattern = $this->getOption( 'regex' );
			$dbnames = $this->getWikiDbNamesByRegex( $pattern );
		} elseif ( (bool)$this->getOption( 'file' ) ) {
			$dbnames = file( $this->getOption( 'file' ), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( !$dbnames ) {
				$this->fatalError( 'Unable to read file, exiting' );
			}
		} else {
			$dbnames[] = $this->getConfig()->get( MainConfigNames::DBname );
		}

		foreach ( $dbnames as $dbname ) {
			$oldVersion = MirahezeFunctions::getMediaWikiVersion( $dbname );
			$newVersion = $this->getOption( 'mwversion' );

			if ( $newVersion !== $oldVersion && is_dir( '/srv/mediawiki/' . $newVersion ) ) {
				if ( $this->hasOption( 'dry-run' ) ) {
					$this->output( "Dry run: Would upgrade $dbname from $oldVersion to $newVersion\n" );
					continue;
				}

				$wiki = new RemoteWiki(
					$dbname,
					$this->getServiceContainer()->get( 'CreateWikiHookRunner' )
				);

				$wiki->newRows['wiki_version'] = $newVersion;
				$wiki->changes['mediawiki-version'] = [
					'old' => $oldVersion,
					'new' => $newVersion,
				];

				$wiki->commit();

				$this->output( "Upgraded $dbname from $oldVersion to $newVersion\n" );
			}
		}
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

$maintClass = ChangeMediaWikiVersion::class;
require_once RUN_MAINTENANCE_IF_MAIN;
