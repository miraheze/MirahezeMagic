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

		$this->addDescription( 'Check for wiki databases across all clusters that are missing in cw_wikis, or for cw_wikis entries that have no database in any cluster.' );
		$this->addOption( 'inverse', 'Check for cw_wikis entries without a matching database in any cluster.', false, false );
		$this->addOption( 'delete', 'Delete/drop missing databases or entries based on the selected option (inverse or not).', false, false );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$clusters = $dbLoadBalancerFactory->getAllMainLBs();
		$wikiDatabases = $this->getWikiDatabasesFromClusters( $clusters );

		if ( !$wikiDatabases ) {
			$this->fatalError( "No wiki databases found.\n" );
		}

		$this->output( 'Found ' . count( $wikiDatabases ) . " wiki databases across clusters.\n" );

		if ( $this->hasOption( 'inverse' ) ) {
			$this->checkCwWikisWithoutDatabase( $wikiDatabases );
			return;
		}

		$missingDatabases = $this->findMissingDatabases( $wikiDatabases );
		if ( $missingDatabases ) {
			$this->output( "Databases missing in cw_wikis:\n" );
			foreach ( $missingDatabases as $dbName ) {
				$this->output( " - $dbName\n" );
			}

			if ( $this->hasOption( 'delete' ) ) {
				$this->dropDatabases( $missingDatabases );
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
			$result = $dbr->newSelectQueryBuilder()
				->select( [ 'SCHEMA_NAME' ] )
				->from( 'information_schema.SCHEMATA' )
				->where( [ 'SCHEMA_NAME' . $dbr->buildLike(
					$dbr->anyString(),
					$this->getConfig()->get( 'CreateWikiDatabaseSuffix' )
				) ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $result as $row ) {
				$wikiDatabases[] = $row->SCHEMA_NAME;
			}
		}

		return $wikiDatabases;
	}

	private function findMissingDatabases( array $wikiDatabases ) {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase(
			$this->getConfig()->get( 'CreateWikiDatabase' )
		);

		$missingDatabases = [];
		foreach ( $wikiDatabases as $dbName ) {
			$result = $dbr->newSelectQueryBuilder()
				->select( [ 'wiki_dbname' ] )
				->from( 'cw_wikis' )
				->where( [ 'wiki_dbname' => $dbName ] )
				->caller( __METHOD__ )
				->fetchRow();

			if ( !$result ) {
				$missingDatabases[] = $dbName;
			}
		}

		return $missingDatabases;
	}

	private function checkCwWikisWithoutDatabase( array $wikiDatabases ) {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase(
			$this->getConfig()->get( 'CreateWikiDatabase' )
		);

		$missingInCluster = [];
		$result = $dbr->newSelectQueryBuilder()
			->select( [ 'wiki_dbname' ] )
			->from( 'cw_wikis' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $result as $row ) {
			$dbName = $row->wiki_dbname;
			if ( !in_array( $dbName, $wikiDatabases ) ) {
				$missingInCluster[] = $dbName;
			}
		}

		if ( $missingInCluster ) {
			$this->output( "cw_wikis entries without a matching database in any cluster:\n" );
			foreach ( $missingInCluster as $dbName ) {
				$this->output( " - $dbName\n" );
			}

			if ( $this->hasOption( 'delete' ) ) {
				$this->deleteCwWikisEntries( $missingInCluster );
			}
		} else {
			$this->output( "All cw_wikis entries have matching databases in the clusters.\n" );
		}
	}

	private function dropDatabases( array $databases ) {
		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$this->output( "Dropping the following databases:\n" );
		foreach ( $databases as $dbName ) {
			$this->output( " - Dropping $dbName...\n" );
			$dbw = $this->getServiceContainer()->getConnectionProvider()
				->getPrimaryDatabase( $dbName );

			$dbw->query( "DROP DATABASE IF EXISTS $dbName", __METHOD__ );
		}
		$this->output( "Database drop operation completed.\n" );
	}

	private function deleteCwWikisEntries( array $entries ) {
		$dbw = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase(
			$this->getConfig()->get( 'CreateWikiDatabase' )
		);

		$this->output( "Deleting the following cw_wikis entries:\n" );
		foreach ( $entries as $dbName ) {
			$this->output( " - Deleting entry $dbName from cw_wikis...\n" );
			$dbw->delete( 'cw_wikis', [ 'wiki_dbname' => $dbName ], __METHOD__ );
		}
		$this->output( "cw_wikis entries deletion completed.\n" );
	}
}

$maintClass = CheckWikiDatabases::class;
require_once RUN_MAINTENANCE_IF_MAIN;
