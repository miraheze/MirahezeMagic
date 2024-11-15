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

class ResetWiki extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Resets a wiki database to what it is when it is first created.' );
		$this->addOption( 'dbname', 'The database name of the wiki to reset.', true, true );
		$this->addOption( 'requester', 'The user to assign initial rights for.', true, true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$databaseName = strtolower( $this->getOption( 'dbname' ) );
		$requester = $this->getOption( 'requester' );

		if ( !$databaseName || !$requester ) {
			$this->fatalError( 'Both --dbname and --requester are required.' );
		}

		$userFactory = $this->getServiceContainer()->getUserFactory();
		if ( !$userFactory->newFromName( $requester ) ) {
			$this->fatalError( "Requester '$requester' is invalid." );
		}

		$this->output( "Resetting database: $databaseName\n" );

		$dbr = $this->getServiceContainer()->getConnectionProvider()
			->getReplicaDatabase( $this->getConfig()->get( 'CreateWikiDatabase' ) );

		// Fetch the original data
		$row = $dbr->newSelectQueryBuilder()
			->from( 'cw_wikis' )
			->field( '*' )
			->where( [ 'wiki_dbname' => $databaseName ] )
			->caller( __METHOD__ )
			->fetchRow();

		$cluster = $row->wiki_dbcluster;
		$sitename = $row->wiki_sitename;
		$language = $row->wiki_language;
		$category = $row->wiki_category;
		$isPrivate = (bool)$row->wiki_private;

		if ( !$cluster ) {
			$this->fatalError( "Cluster for $databaseName not found in cw_wikis." );
		}

		// Get the load balancer for the specific cluster
		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$loadBalancer = $dbLoadBalancerFactory->getAllMainLBs()[$cluster];

		// Get the connection to the specific cluster that the wiki's database exists on
		$dbw = $loadBalancer->getConnection( DB_PRIMARY, [], ILoadBalancer::DOMAIN_ANY );

		// Check if the database exists
		$dbExists = $dbw->newSelectQueryBuilder()
			->from( 'information_schema.SCHEMATA' )
			->field( 'SCHEMA_NAME' )
			->where( [ 'SCHEMA_NAME' => $databaseName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$dbExists ) {
			$this->fatalError( "Database $databaseName does not exist on cluster $cluster." );
		}

		$databaseQuotes = $dbw->addIdentifierQuotes( $databaseName );

		$dataFactory = $this->getServiceContainer()->get( 'CreateWikiDataFactory' );
		$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );

		// Delete the wiki from CreateWiki
		$wikiManager = $wikiManagerFactory->newInstance( $databaseName );
		$wikiManager->delete( force: true );

		// Drop the database
		$dbw->query( "DROP DATABASE $databaseQuotes", __METHOD__ );

		// Create a new WikiManagerFactory instance
		$wikiManager = $wikiManagerFactory->newInstance( $databaseName );

		// This runs checkDatabaseName and if it returns a
		// non-null value it is returning an error.
		$notCreated = $wikiManager->create(
			sitename: $sitename,
			language: $language,
			private: $isPrivate,
			category: $category,
			requester: $requester,
			extra: [],
			actor: '',
			reason: ''
		);

		if ( $notCreated ) {
			$this->fatalError( $notCreated );
		}
		
		$data = $dataFactory->newInstance( $databaseName );
		$data->resetWikiData( isNewChanges: true );

		$this->output( "Database recreated successfully.\n" );
	}
}

$maintClass = ResetWiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
