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
 * @ingroup Maintenance
 * @author Universal Omega
 * @version 1.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Wikimedia\Rdbms\ILoadBalancer;

class CheckWikiDatabases extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Check for wiki databases across all clusters that are missing in cw_wikis.' );
		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$clusters = $dbLoadBalancerFactory->getAllMainLBs();
		$wikiDatabases = $this->getWikiDatabasesFromClusters( $clusters );
		if ( empty( $wikiDatabases ) ) {
			$this->output( "No wiki databases found.\n" );
			return;
		}
		$this->output( 'Found ' . count( $wikiDatabases ) . " wiki databases across clusters.\n" );
		$missingDatabases = $this->findMissingDatabases( $wikiDatabases );
		if ( !empty( $missingDatabases ) ) {
			$this->output( "Databases missing in cw_wikis:\n" );
			foreach ( $missingDatabases as $dbName ) {
				$this->output( " - $dbName\n" );
			}
		} else {
			$this->output( "All wiki databases are present in cw_wikis.\n" );
		}
	}

	private function getWikiDatabasesFromClusters( array $clusters ) {
		$wikiDatabases = [];
		foreach ( $clusters as $cluster => $loadBalancer ) {
			$this->output( "Connecting to cluster: $cluster...\n" );
			$dbr = $loadBalancer->getConnection( DB_REPLICA, [], ILoadBalancer::DOMAIN_ANY );
			// Get wiki databases from information_schema
			$result = $dbr->newSelectQueryBuilder()
				->select( [ 'SCHEMA_NAME' ] )
				->from( 'information_schema.SCHEMATA' )
				->where( [ 'SCHEMA_NAME' . $dbr->buildLike( $dbr->anyString(), $this->getConfig()->get( 'CreateWikiDatabaseSuffix' ) ) ] )
				->caller( __METHOD__ )
				->fetchResultSet();
			foreach ( $result as $row ) {
				$wikiDatabases[] = $row->SCHEMA_NAME;
			}
		}
		return $wikiDatabases;
	}

	private function findMissingDatabases( array $wikiDatabases ) {
		$dbr = $this->getServiceContainer()->getDBLoadBalancer()->getConnectionRef( DB_REPLICA, [], $this->getConfig()->get( 'CreateWikiDatabase' ) );
		$missingDatabases = [];
	// Loop through each wiki database and check if it's missing from cw_wikis
		foreach ( $wikiDatabases as $dbName ) {
			// Query to check if the database exists in cw_wikis
			$result = $dbr->newSelectQueryBuilder()
			->select( [ 'database_name' => 'cw.wiki_dbname' ] )
			->from( 'cw_wikis', 'cw' )
			->where( [ 'cw.wiki_dbname' => $dbName ] )
			->caller( __METHOD__ )
			->fetchRow();
			// If the database is not found in cw_wikis, add it to the missing list
			if ( !$result ) {
				$missingDatabases[] = $dbName;
			}
		}
		return $missingDatabases;
	}
}

$maintClass = CheckWikiDatabases::class;
require_once RUN_MAINTENANCE_IF_MAIN;
