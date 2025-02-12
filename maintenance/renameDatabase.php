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

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\ILoadBalancer;

class RenameDatabase extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Rename a database by creating a new one and moving all tables from the old one.' );
		$this->addOption( 'old', 'The name of the old database to rename.', true, true );
		$this->addOption( 'new', 'The new name for the database.', true, true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$oldDatabaseName = strtolower( $this->getOption( 'old' ) );
		$newDatabaseName = strtolower( $this->getOption( 'new' ) );

		if ( !$oldDatabaseName || !$newDatabaseName ) {
			$this->fatalError( 'Both old and new database names are required.' );
		}

		$suffix = $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );
		if ( !str_ends_with( $oldDatabaseName, $suffix ) || !str_ends_with( $newDatabaseName, $suffix ) ) {
			$this->fatalError( "ERROR: Cannot rename $oldDatabaseName to $newDatabaseName because it ends in an invalid suffix." );
		}

		if ( !ctype_alnum( $newDatabaseName ) ) {
			$this->fatalError( "ERROR: Cannot rename $oldDatabaseName to $newDatabaseName because it contains non-alphanumeric characters." );
		}

		$this->output( "Renaming database: $oldDatabaseName to $newDatabaseName\n" );

		$dbr = $this->getServiceContainer()->getConnectionProvider()
			->getReplicaDatabase( 'virtual-createwiki' );

		// Fetch the specific cluster from cw_wikis based on the old database name
		$cluster = $dbr->newSelectQueryBuilder()
			->from( 'cw_wikis' )
			->field( 'wiki_dbcluster' )
			->where( [ 'wiki_dbname' => $oldDatabaseName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$cluster ) {
			$this->fatalError( "Cluster for $oldDatabaseName not found in cw_wikis." );
		}

		// Get the load balancer for the specific cluster
		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$loadBalancer = $dbLoadBalancerFactory->getAllMainLBs()[$cluster];

		// Get the connection to the specific cluster that the wiki's database exists on
		$dbw = $loadBalancer->getConnection( DB_PRIMARY, [], ILoadBalancer::DOMAIN_ANY );

		// Check if the old database exists
		$oldDbExists = $dbw->newSelectQueryBuilder()
			->from( 'information_schema.SCHEMATA' )
			->field( 'SCHEMA_NAME' )
			->where( [ 'SCHEMA_NAME' => $oldDatabaseName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$oldDbExists ) {
			$this->fatalError( "Database $oldDatabaseName does not exist on cluster $cluster." );
		}

		// Check if the new database already exists
		$newDbExists = $dbw->newSelectQueryBuilder()
			->from( 'information_schema.SCHEMATA' )
			->field( 'SCHEMA_NAME' )
			->where( [ 'SCHEMA_NAME' => $newDatabaseName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $newDbExists ) {
			$this->fatalError( "Database $newDatabaseName already exists on cluster $cluster." );
		}

		$oldDatabaseQuotes = $dbw->addIdentifierQuotes( $oldDatabaseName );
		$newDatabaseQuotes = $dbw->addIdentifierQuotes( $newDatabaseName );

		// Create the new database
		$dbw->query( "CREATE DATABASE $newDatabaseQuotes", __METHOD__ );

		// Fetch all tables in the old database
		$tableNames = [];
		$res = $dbw->newSelectQueryBuilder()
			->select( [ 'TABLE_NAME' ] )
			->from( 'information_schema.TABLES' )
			->where( [ 'TABLE_SCHEMA' => $oldDatabaseName ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$tableNames[] = $row->TABLE_NAME;
		}

		// Rename each table to the new database
		foreach ( $tableNames as $table ) {
			$tableQuotes = $dbw->addIdentifierQuotes( $table );
			$this->output( "Moving table $table to $newDatabaseName...\n" );
			$dbw->query( "RENAME TABLE {$oldDatabaseQuotes}.{$tableQuotes} TO {$newDatabaseQuotes}.{$tableQuotes}", __METHOD__ );
		}

		$this->output( "Database renamed successfully on cluster $cluster.\n" );
	}
}

$maintClass = RenameDatabase::class;
require_once RUN_MAINTENANCE_IF_MAIN;
