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

		$this->addDescription( 'Check for wiki databases across all clusters that are missing in cw_wikis, or for table entries that have no database in any cluster.' );
		$this->addOption( 'tables', 'Check for database field entries in global database tables without a matching database in any cluster.', false, false );
		$this->addOption( 'delete', 'Delete/drop missing databases or entries based on the selected option (tables or databases).', false, false );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();

		$clusters = $dbLoadBalancerFactory->getAllMainLBs();
		ksort( $clusters );

		$wikiDatabases = $this->getWikiDatabasesFromClusters( $clusters );

		if ( $this->hasOption( 'tables' ) ) {
			$this->checkGlobalTableEntriesWithoutDatabase( $wikiDatabases );
			return;
		}

		if ( !$wikiDatabases ) {
			$this->fatalError( 'No wiki databases found.' );
		}

		$this->output( 'Found ' . count( $wikiDatabases ) . " wiki databases across clusters.\n" );

		$missingDatabases = $this->findMissingDatabases( $wikiDatabases );
		if ( $missingDatabases ) {
			$this->output( "Databases missing in cw_wikis:\n" );
			foreach ( array_keys( $missingDatabases ) as $dbName ) {
				$this->output( " - $dbName\n" );
			}

			if ( $this->hasOption( 'delete' ) ) {
				$this->dropDatabases( $missingDatabases, $clusters );
			}
		} else {
			$this->output( "All wiki databases are present in cw_wikis.\n" );
		}
	}

	private function getWikiDatabasesFromClusters( array $clusters ): array {
		$suffix = $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );

		$wikiDatabases = [];
		foreach ( $clusters as $cluster => $loadBalancer ) {
			// We don't need the DEFAULT cluster
			if ( $cluster === 'DEFAULT' ) {
				continue;
			}

			$this->output( "Connecting to cluster: $cluster...\n" );
			$dbr = $loadBalancer->getConnection( DB_REPLICA, [], ILoadBalancer::DOMAIN_ANY );
			$result = $dbr->newSelectQueryBuilder()
				->select( [ 'SCHEMA_NAME' ] )
				->from( 'information_schema.SCHEMATA' )
				->where( [ 'SCHEMA_NAME' . $dbr->buildLike(
					$dbr->anyString(), $suffix, $dbr->anyString()
				) ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $result as $row ) {
				if (
					str_ends_with( $row->SCHEMA_NAME, $suffix ) ||
					str_ends_with( $row->SCHEMA_NAME, $suffix . 'cargo' )
				) {
					$wikiDatabases[$row->SCHEMA_NAME] = $cluster;
				}
			}
		}

		ksort( $wikiDatabases );
		return $wikiDatabases;
	}

	private function findMissingDatabases( array $wikiDatabases ): array {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase(
			$this->getConfig()->get( 'CreateWikiDatabase' )
		);

		$missingDatabases = [];
		foreach ( $wikiDatabases as $dbName => $cluster ) {
			$trimmed = rtrim( $dbName, 'cargo' );
			$result = $dbr->newSelectQueryBuilder()
				->select( [ 'wiki_dbname' ] )
				->from( 'cw_wikis' )
				->where( [ 'wiki_dbname' => $trimmed ] )
				->caller( __METHOD__ )
				->fetchRow();

			if ( !$result ) {
				$missingDatabases[$dbName] = $cluster;
			}
		}

		return $missingDatabases;
	}

	private function checkGlobalTableEntriesWithoutDatabase( array $wikiDatabases ): void {
		$suffix = $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );

		$tablesToCheck = [
			'CreateWikiDatabase' => [
				'cw_wikis' => 'wiki_dbname',
				'gnf_files' => 'files_dbname',
				'localnames' => 'ln_wiki',
				'localuser' => 'lu_wiki',
				'mw_namespaces' => 'ns_dbname',
				'mw_permissions' => 'perm_dbname',
				'mw_settings' => 's_dbname',
			],
			'EchoSharedTrackingDB' => [
				'echo_unread_wikis' => 'euw_wiki',
			],
			'GlobalUsageDatabase' => [
				'globalimagelinks' => 'gil_wiki',
			],
		];

		$missingInCluster = [];

		foreach ( $tablesToCheck as $dbConfigKey => $tables ) {
			$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase(
				$this->getConfig()->get( $dbConfigKey )
			);

			foreach ( $tables as $table => $field ) {
				$this->output( "Checking table: $table, field: $field...\n" );
				$result = $dbr->newSelectQueryBuilder()
					->select( [ $field ] )
					->from( $table )
					->caller( __METHOD__ )
					->fetchResultSet();

				foreach ( $result as $row ) {
					$dbName = $row->$field;

					// Safety check for suffix and filtering out 'default'
					if ( !str_ends_with( $dbName, $suffix ) || $dbName === 'default' ) {
						continue;
					}

					if ( !in_array( $dbName, $wikiDatabases ) ) {
						$missingInCluster[] = $dbName;
					}
				}
			}
		}

		// Filter to only unique entries
		$missingInCluster = array_unique( $missingInCluster );
		sort( $missingInCluster );

		if ( $missingInCluster ) {
			$this->output( "Entries without a matching database in any cluster:\n" );
			foreach ( $missingInCluster as $dbName ) {
				$this->output( " - $dbName\n" );
			}

			if ( $this->hasOption( 'delete' ) ) {
				$this->deleteEntries( $missingInCluster, $tablesToCheck );
			}
		} else {
			$this->output( "All entries in specified tables have matching databases in the clusters.\n" );
		}
	}

	private function dropDatabases( array $databases, array $clusters ): void {
		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$this->output( "Dropping the following databases:\n" );
		foreach ( $databases as $dbName => $cluster ) {
			$this->output( " - Dropping $dbName...\n" );
			$dbw = $clusters[$cluster]->getConnection( DB_PRIMARY, [], ILoadBalancer::DOMAIN_ANY );
			$dbw->query( "DROP DATABASE IF EXISTS $dbName", __METHOD__ );
		}

		$this->output( "Database drop operation completed.\n" );
	}

	private function deleteEntries(
		array $missingInCluster,
		array $tablesToCheck
	): void {
		$suffix = $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );

		foreach ( $tablesToCheck as $dbConfigKey => $tables ) {
			$dbw = $this->getServiceContainer()->getConnectionProvider()->getPrimaryDatabase(
				$this->getConfig()->get( $dbConfigKey )
			);

			foreach ( $tables as $table => $field ) {
				$this->output( "Deleting missing entries from table: $table, field: $field...\n" );

				foreach ( $missingInCluster as $dbName ) {
					// Only delete entries that end with the suffix for safety
					if ( !str_ends_with( $dbName, $suffix ) || $dbName === 'default' ) {
						continue;
					}

					$dbw->newDeleteQueryBuilder()
						->deleteFrom( $table )
						->where( [ $field => $dbName ] )
						->caller( __METHOD__ )
						->execute();
				}
			}
		}

		$this->output( "Deletion of missing entries completed.\n" );
	}
}

$maintClass = CheckWikiDatabases::class;
require_once RUN_MAINTENANCE_IF_MAIN;
