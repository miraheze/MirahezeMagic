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
 * @version 2.0
 */

use MediaWiki\Maintenance\Maintenance;
use Miraheze\CreateWiki\ConfigNames;
use RuntimeException;
use Throwable;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILoadBalancer;

class RenameDatabase extends Maintenance {

	private array $tablesMoved = [];
	private bool $hasCargoDB = false;

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Rename a database by creating a new one and moving all tables from the old one.' );
		$this->addOption( 'rename', 'Specify to actually perform the rename. Otherwise just performs a dry run.' );
		$this->addOption( 'old', 'The name of the old database to rename.', true, true );
		$this->addOption( 'new', 'The new name for the database.', true, true );

		$this->addOption( 'user',
			'Username or reference name of the person running this script. ' .
			'Will be used in tracking and notification internally.',
		true, true );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		[ $oldDatabaseName, $newDatabaseName, $dryRun ] = $this->parseAndValidateOptions();
		$this->validateSuffixAndAlnum( $oldDatabaseName, $newDatabaseName );

		if ( $dryRun ) {
			$this->output( "Dry run mode enabled. No changes will be made. Use --rename to actually perform the rename.\n" );
		}

		if ( !$dryRun ) {
			$this->output( "Renaming database: $oldDatabaseName to $newDatabaseName. If this is wrong, Ctrl-C now!\n" );
			$this->countDown( 10 );
		}

		[ $cluster, $dbw ] = $this->getClusterAndDbw( $oldDatabaseName );
		$this->verifyDatabaseExistence( $dbw, $oldDatabaseName, $newDatabaseName, $cluster );

		$dbCollation = $this->getConfig()->get( ConfigNames::Collation );
		$oldDatabaseQuotes = $dbw->addIdentifierQuotes( $oldDatabaseName );
		$newDatabaseQuotes = $dbw->addIdentifierQuotes( $newDatabaseName );

		try {
			$this->createNewDatabase( $dbw, $newDatabaseQuotes, $dbCollation, $dryRun );
			$this->moveTables( $dbw, $oldDatabaseQuotes, $newDatabaseQuotes, $oldDatabaseName, $dryRun );

			if ( !$dryRun ) {
				$this->performWikiRename( $oldDatabaseName, $newDatabaseName );
				$this->sendNotification( $oldDatabaseName, $newDatabaseName );
			}
		} catch ( Throwable $t ) {
			$errorMessage = $t->getMessage();
			$this->output( "Error occurred: $errorMessage\nAttempting rollback...\n" );
			$this->rollbackTables(
				$dbw, $oldDatabaseQuotes, $newDatabaseQuotes,
				$oldDatabaseName, $newDatabaseName,
				$errorMessage, $dryRun
			);
			if ( !$dryRun ) {
				try {
					$this->performWikiRename( $newDatabaseName, $oldDatabaseName );
				} catch ( Throwable $t ) {
					$this->output( "Rollback for CreateWiki rename failed: {$t->getMessage()}\n" );
				}
			}
			$this->fatalError( "Error during rename: $errorMessage" );
		}

		if ( $dryRun ) {
			$this->output( "DRY RUN: Database rename simulation complete on cluster $cluster.\n" );

			if ( $this->hasCargoDB ) {
				$this->output( "Found Cargo database: {$oldDatabaseName}cargo. It will not be renamed; you may need to rename it manually.\n" );
			}

			return;
		}

		$this->output( "Database renamed successfully on cluster $cluster.\n" );

		if ( $this->hasCargoDB ) {
			$this->output( "Found Cargo database: {$oldDatabaseName}cargo. It was not renamed; you may need to rename it manually.\n" );
		}
	}

	private function parseAndValidateOptions(): array {
		$oldDatabaseName = strtolower( $this->getOption( 'old' ) );
		$newDatabaseName = strtolower( $this->getOption( 'new' ) );
		$dryRun = !$this->hasOption( 'rename' );

		if ( !$oldDatabaseName || !$newDatabaseName ) {
			$this->fatalError( 'Both old and new database names are required.' );
		}

		return [ $oldDatabaseName, $newDatabaseName, $dryRun ];
	}

	private function validateSuffixAndAlnum(
		string $oldDatabaseName,
		string $newDatabaseName
	): void {
		$suffix = $this->getConfig()->get( ConfigNames::DatabaseSuffix );
		if ( !str_ends_with( $oldDatabaseName, $suffix ) || !str_ends_with( $newDatabaseName, $suffix ) ) {
			$this->fatalError( "ERROR: Cannot rename $oldDatabaseName to $newDatabaseName because it ends in an invalid suffix." );
		}

		if ( !ctype_alnum( $newDatabaseName ) ) {
			$this->fatalError( "ERROR: Cannot rename $oldDatabaseName to $newDatabaseName because it contains non-alphanumeric characters." );
		}
	}

	private function performWikiRename(
		string $oldDatabaseName,
		string $newDatabaseName
	): void {
		$wikiManagerFactory = $this->getServiceContainer()->get( 'WikiManagerFactory' );
		$wikiManager = $wikiManagerFactory->newInstance( $oldDatabaseName );
		$rename = $wikiManager->rename( $newDatabaseName );

		if ( $rename ) {
			throw new RuntimeException( $rename );
		}
	}

	private function getClusterAndDbw( string $oldDatabaseName ): array {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbr = $databaseUtils->getGlobalReplicaDB();

		$cluster = $dbr->newSelectQueryBuilder()
			->from( 'cw_wikis' )
			->field( 'wiki_dbcluster' )
			->where( [ 'wiki_dbname' => $oldDatabaseName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$cluster ) {
			$this->fatalError( "Cluster for $oldDatabaseName not found in cw_wikis." );
		}

		$dbLoadBalancerFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$loadBalancer = $dbLoadBalancerFactory->getAllMainLBs()[ $cluster ];
		$dbw = $loadBalancer->getConnection( DB_PRIMARY, [], ILoadBalancer::DOMAIN_ANY );

		return [ $cluster, $dbw ];
	}

	private function verifyDatabaseExistence(
		DBConnRef $dbw,
		string $oldDatabaseName,
		string $newDatabaseName,
		string $cluster
	): void {
		$oldDbExists = $dbw->newSelectQueryBuilder()
			->from( 'information_schema.SCHEMATA' )
			->field( 'SCHEMA_NAME' )
			->where( [ 'SCHEMA_NAME' => $oldDatabaseName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$oldDbExists ) {
			$this->fatalError( "Database $oldDatabaseName does not exist on cluster $cluster." );
		}

		$newDbExists = $dbw->newSelectQueryBuilder()
			->from( 'information_schema.SCHEMATA' )
			->field( 'SCHEMA_NAME' )
			->where( [ 'SCHEMA_NAME' => $newDatabaseName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $newDbExists ) {
			$this->fatalError( "Database $newDatabaseName already exists on cluster $cluster." );
		}

		// Check for cargo database
		$this->hasCargoDB = (bool)$dbw->newSelectQueryBuilder()
			->from( 'information_schema.SCHEMATA' )
			->field( 'SCHEMA_NAME' )
			->where( [ 'SCHEMA_NAME' => $oldDatabaseName . 'cargo' ] )
			->caller( __METHOD__ )
			->fetchField();
	}

	private function createNewDatabase(
		DBConnRef $dbw,
		string $newDatabaseQuotes,
		string $dbCollation,
		bool $dryRun
	): void {
		if ( $dryRun ) {
			$this->output( "DRY RUN: Would execute query: CREATE DATABASE {$newDatabaseQuotes} {$dbCollation};\n" );
		} else {
			$dbw->query( "CREATE DATABASE {$newDatabaseQuotes} {$dbCollation};", __METHOD__ );
		}
	}

	private function moveTables(
		DBConnRef $dbw,
		string $oldDatabaseQuotes,
		string $newDatabaseQuotes,
		string $oldDatabaseName,
		bool $dryRun
	): void {
		$this->tablesMoved = [];
		$tableNames = $dbw->newSelectQueryBuilder()
			->select( 'TABLE_NAME' )
			->from( 'information_schema.TABLES' )
			->where( [ 'TABLE_SCHEMA' => $oldDatabaseName ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		foreach ( $tableNames as $tableName ) {
			$tableQuotes = $dbw->addIdentifierQuotes( $tableName );
			if ( $dryRun ) {
				$this->output( "DRY RUN: Would execute query: RENAME TABLE {$oldDatabaseQuotes}.{$tableQuotes} TO {$newDatabaseQuotes}.{$tableQuotes};\n" );
				$this->tablesMoved[] = $tableName;
			} else {
				$this->output( "Moving table $tableName to $newDatabaseQuotes...\n" );
				$dbw->query( "RENAME TABLE {$oldDatabaseQuotes}.{$tableQuotes} TO {$newDatabaseQuotes}.{$tableQuotes};", __METHOD__ );
				$this->tablesMoved[] = $tableName;
			}
		}
	}

	private function sendNotification(
		string $oldDatabaseName,
		string $newDatabaseName
	): void {
		$user = str_replace( '_', ' ', $this->getOption( 'user' ) );
		$message = "Hello!\nThis is an automatic notification from CreateWiki notifying you that " .
			"just now $user has renamed the following wiki from CreateWiki and " .
			"associated extensions - From $oldDatabaseName to $newDatabaseName.";

		$notificationData = [
			'type' => 'wiki-rename',
			'subject' => 'Wiki Rename Notification',
			'body' => $message,
		];

		$this->getServiceContainer()->get( 'CreateWikiNotificationsManager' )
			->sendNotification(
				data: $notificationData,
				receivers: []
			);
	}

	private function rollbackTables(
		DBConnRef $dbw,
		string $oldDatabaseQuotes,
		string $newDatabaseQuotes,
		string $oldDatabaseName,
		string $newDatabaseName,
		string $errorMessage,
		bool $dryRun
	): void {
		try {
			foreach ( $this->tablesMoved as $tableName ) {
				$tableQuotes = $dbw->addIdentifierQuotes( $tableName );
				if ( $dryRun ) {
					$this->output( "DRY RUN: Would rollback table $tableName to $oldDatabaseName...\n" );
				} else {
					$this->output( "Rolling back table $tableName to $oldDatabaseName...\n" );
					$dbw->query( "RENAME TABLE {$newDatabaseQuotes}.{$tableQuotes} TO {$oldDatabaseQuotes}.{$tableQuotes};", __METHOD__ );
				}
			}

			if ( $dryRun ) {
				$this->output( "DRY RUN: Rollback simulation complete. You may need to DROP $newDatabaseName in order to try this again.\n" );
			} else {
				$this->output( "Rollback successful. You may need to DROP $newDatabaseName in order to try this again.\n" );
			}
		} catch ( Throwable $t ) {
			$this->output( "Rollback failed: {$t->getMessage()}\n" );
			$this->fatalError( "Original error: $errorMessage. Rollback error: {$t->getMessage()}" );
		}
	}
}

// @codeCoverageIgnoreStart
return RenameDatabase::class;
// @codeCoverageIgnoreEnd
