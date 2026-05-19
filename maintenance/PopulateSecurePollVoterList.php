<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Throwable;
use Wikimedia\Rdbms\IReadableDatabase;

class PopulateSecurePollVoterList extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption(
			'step',
			'Step to run: 1 (per-wiki JSON generation) or 2 (merge and insert)',
			true,
			true
		);
		$this->addOption(
			'output-dir',
			'[Step 1] Directory to write per-wiki JSON files to',
			false,
			true
		);
		$this->addOption(
			'wiki',
			'[Step 1] Process a single wiki by dbname',
			false,
			true
		);
		$this->addOption(
			'all-wikis',
			'[Step 1] Iterate all wikis from LocalDatabases',
			false,
			false
		);
		$this->addOption(
			'before',
			'[Step 1] Only count contributions before this date (MW timestamp or strtotime format)',
			false,
			true
		);
		$this->addOption(
			'force',
			'[Step 1] Overwrite existing JSON files (default: skip wikis with existing output)',
			false,
			false
		);
		$this->addOption(
			'input-dir',
			'[Step 2] Directory containing Step 1 JSON files',
			false,
			true
		);
		$this->addOption(
			'list-name',
			'[Step 2] Name of the securepoll_lists entry to create',
			false,
			true
		);
		$this->addOption(
			'min-contributions',
			'Minimum contributions (edits + log actions) required to include a user. '
			. 'In step 1: filters entries written to JSON. In step 2: filters during merge (required).',
			false,
			true
		);
		$this->addOption(
			'replace',
			'[Step 2] Delete existing list before repopulating (default: abort if list exists)',
			false,
			false
		);
		$this->addOption(
			'dry-run',
			'[Step 2] Simulate without writing to the database',
			false,
			false
		);

		$this->addDescription(
			'Populate a SecurePoll voter eligibility list based on cross-wiki '
			. 'edit and log action counts. Run in two steps: ' . "\n"
			. '  Step 1: Generate per-wiki contribution counts as JSON files. ' . "\n"
			. '  Step 2: Merge JSON files, apply threshold, insert into local securepoll_lists.'
		);
		$this->requireExtension( 'CreateWiki' );
		$this->requireExtension( 'CentralAuth' );
		$this->setBatchSize( 500 );
	}

	public function execute() {
		$step = (int)$this->getOption( 'step' );

		if ( $step === 1 ) {
			$this->executeStep1();
		} elseif ( $step === 2 ) {
			$this->executeStep2();
		} else {
			$this->fatalError( 'Invalid --step value. Must be 1 or 2.' );
		}
	}

	private function executeStep1(): void {
		$outputDir = $this->getOption( 'output-dir' );
		if ( $outputDir === null ) {
			$this->fatalError( '--output-dir is required for step 1.' );
		}

		$singleWiki = $this->getOption( 'wiki' );
		$allWikis = $this->hasOption( 'all-wikis' );

		if ( $singleWiki === null && !$allWikis ) {
			$this->fatalError( 'Specify --wiki=<dbname> or --all-wikis for step 1.' );
		}

		$before = $this->getOption( 'before' );
		$force = $this->hasOption( 'force' );

		$minContributions = $this->getOption( 'min-contributions' );
		if ( $minContributions !== null ) {
			$minContributions = (int)$minContributions;
		}

		if ( !is_dir( $outputDir ) ) {
			if ( !mkdir( $outputDir, 0755, true ) ) {
				$this->fatalError( "Could not create output directory: $outputDir" );
			}
		}

		$wikis = $allWikis
			? $this->getNonDeletedLocalWikis()
			: [ $singleWiki ];

		$totalWikis = count( $wikis );
		$this->output( "Step 1: Processing $totalWikis wiki(s)...\n" );

		$processed = 0;
		$skipped = 0;

		foreach ( $wikis as $i => $wiki ) {
			$jsonFile = "$outputDir/$wiki.json";

			if ( !$force && file_exists( $jsonFile ) ) {
				$skipped++;
				continue;
			}

			try {
				$contribs = $this->getWikiContributions( $wiki, $before );
				if ( $minContributions !== null ) {
					$contribs = array_filter(
						$contribs,
						static fn ( int $count ) => $count >= $minContributions
					);
				}
			} catch ( Throwable $e ) {
				$this->output( "  Skipping $wiki (error: " . $e->getMessage() . ")\n" );
				continue;
			}

			$encoded = json_encode( $contribs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( file_put_contents( $jsonFile, $encoded ) === false ) {
				$this->error( "  Failed to write $jsonFile\n" );
				continue;
			}

			$processed++;

			if ( (int)( $i + 1 ) % 100 === 0 || $i === $totalWikis - 1 ) {
				$this->output(
					"  " . ( $i + 1 ) . " / $totalWikis wikis "
					. "($processed written, $skipped skipped)\n"
				);
			}
		}

		$this->output( "Step 1 complete: $processed wikis written, $skipped wikis skipped.\n" );
	}

	private function executeStep2(): void {
		$inputDir = $this->getOption( 'input-dir' );
		$listName = $this->getOption( 'list-name' );
		$minContributions = $this->getOption( 'min-contributions' );

		if ( $inputDir === null ) {
			$this->fatalError( '--input-dir is required for step 2.' );
		}
		if ( $listName === null ) {
			$this->fatalError( '--list-name is required for step 2.' );
		}
		if ( $minContributions === null ) {
			$this->fatalError( '--min-contributions is required for step 2.' );
		}
		$minContributions = (int)$minContributions;

		$replace = $this->hasOption( 'replace' );
		$dryRun = $this->hasOption( 'dry-run' );

		if ( !is_dir( $inputDir ) ) {
			$this->fatalError( "Input directory does not exist: $inputDir" );
		}

		$jsonFiles = glob( "$inputDir/*.json" );
		if ( !$jsonFiles ) {
			$this->fatalError( "No JSON files found in $inputDir." );
		}

		$this->output( "Step 2: Merging " . count( $jsonFiles ) . " JSON file(s)...\n" );

		$qualifyingUsers = [];
		$filesRead = 0;

		foreach ( $jsonFiles as $jsonFile ) {
			$content = file_get_contents( $jsonFile );
			if ( $content === false ) {
				$this->error( "  Failed to read $jsonFile, skipping.\n" );
				continue;
			}

			$wikiContribs = json_decode( $content, true );
			if ( !is_array( $wikiContribs ) ) {
				$this->error( "  Invalid JSON in $jsonFile, skipping.\n" );
				continue;
			}

			foreach ( $wikiContribs as $actorName => $count ) {
				if ( !isset( $qualifyingUsers[$actorName] ) && $count >= $minContributions ) {
					$qualifyingUsers[$actorName] = true;
				}
			}

			$filesRead += 1;
		}

		$this->output(
			"Read $filesRead file(s). Found "
			. count( $qualifyingUsers ) . " users with at least $minContributions"
			. " contributions on a single wiki.\n"
		);

		if ( !$qualifyingUsers ) {
			$this->output( "No qualifying users found.\n" );
			return;
		}

		$dbr = $this->getReplicaDB();

		$existingCount = (int)$dbr->newSelectQueryBuilder()
								  ->select( 'COUNT(*)' )
								  ->from( 'securepoll_lists' )
								  ->where( [ 'li_name' => $listName ] )
								  ->caller( __METHOD__ )
								  ->fetchField();

		if ( $existingCount > 0 && !$replace ) {
			$this->fatalError(
				"List '$listName' already exists with $existingCount members. "
				. "Use --replace to overwrite."
			);
		}

		$userNames = array_keys( $qualifyingUsers );
		$userIdMap = $this->lookupLocalUserIds( $dbr, $userNames );

		$insertBatch = [];
		$mapped = 0;
		foreach ( $userNames as $actorName ) {
			if ( isset( $userIdMap[$actorName] ) ) {
				$insertBatch[] = [
					'li_name' => $listName,
					'li_member' => $userIdMap[$actorName],
				];
				$mapped++;
			}
		}

		$unmapped = count( $qualifyingUsers ) - $mapped;
		$this->output(
			"$mapped users qualified after mapping to local accounts."
			. ( $unmapped > 0 ? " ($unmapped had no local account)" : '' ) . "\n"
		);

		if ( $dryRun ) {
			$this->output( "Dry run: would insert $mapped rows into securepoll_lists.\n" );
			return;
		}

		$dbw = $this->getPrimaryDB();

		if ( $existingCount > 0 && $replace ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'securepoll_lists' )
				->where( [ 'li_name' => $listName ] )
				->caller( __METHOD__ )
				->execute();
			$this->output( "Deleted existing list '$listName' ($existingCount rows).\n" );
		}

		foreach ( array_chunk( $insertBatch, $this->getBatchSize() ) as $batch ) {
			$this->beginTransactionRound( __METHOD__ );
			$dbw->newInsertQueryBuilder()
				->insertInto( 'securepoll_lists' )
				->rows( $batch )
				->caller( __METHOD__ )
				->execute();
			$this->commitTransactionRound( __METHOD__ );
		}

		$this->output( "Inserted $mapped users into list '$listName'.\n" );
	}

	private function getNonDeletedLocalWikis(): array {
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$cwDbr = $databaseUtils->getGlobalReplicaDB();

		$nonDeleted = array_flip(
			$cwDbr->newSelectQueryBuilder()
				  ->select( 'wiki_dbname' )
				  ->from( 'cw_wikis' )
				  ->where( [ 'wiki_deleted' => 0 ] )
				  ->caller( __METHOD__ )
				  ->fetchFieldValues()
		);

		return array_values(
			array_filter(
				$this->getConfig()->get( MainConfigNames::LocalDatabases ),
				static fn ( string $wiki ) => isset( $nonDeleted[$wiki] )
			)
		);
	}

	private function getWikiContributions( string $wiki, ?string $before ): array {
		$caDbManager = CentralAuthServices::getDatabaseManager();
		$dbr = $caDbManager->getLocalDB( DB_REPLICA, $wiki );

		$actorCounts = [];

		$revQuery = $dbr->newSelectQueryBuilder()
						->select(
							[
								'rev_actor',
								'COUNT(*) AS cnt'
							]
						)
						->from( 'revision' )
						->groupBy( 'rev_actor' )
						->caller( __METHOD__ );

		if ( $before !== null ) {
			$revQuery->where( $dbr->expr( 'rev_timestamp', '<', $before ) );
		}

		$res = $revQuery->fetchResultSet();
		foreach ( $res as $row ) {
			$actorCounts[(int)$row->rev_actor] = (int)$row->cnt;
		}

		$logQuery = $dbr->newSelectQueryBuilder()
						->select(
							[
								'log_actor',
								'COUNT(*) AS cnt'
							]
						)
						->from( 'logging' )
						->groupBy( 'log_actor' )
						->caller( __METHOD__ );

		if ( $before !== null ) {
			$logQuery->where( $dbr->expr( 'log_timestamp', '<', $before ) );
		}

		$res = $logQuery->fetchResultSet();
		foreach ( $res as $row ) {
			$id = (int)$row->log_actor;
			$actorCounts[$id] = ( $actorCounts[$id] ?? 0 ) + (int)$row->cnt;
		}

		if ( !$actorCounts ) {
			return [];
		}

		$actorIds = array_keys( $actorCounts );
		$res = $dbr->newSelectQueryBuilder()
				   ->select(
					   [
						   'actor_id',
						   'actor_name'
					   ]
				   )
				   ->from( 'actor' )
				   ->where( [
					   'actor_id' => $actorIds,
				   ] )
				   ->andWhere( $dbr->expr( 'actor_user', '>', 0 ) )
				   ->caller( __METHOD__ )
				   ->fetchResultSet();

		$result = [];
		foreach ( $res as $row ) {
			$result[$row->actor_name] = $actorCounts[(int)$row->actor_id];
		}

		return $result;
	}

	private function lookupLocalUserIds( IReadableDatabase $dbr, array $userNames ): array {
		$result = [];

		foreach ( array_chunk( $userNames, $this->getBatchSize() ) as $batch ) {
			$res = $dbr->newSelectQueryBuilder()
					   ->select(
						   [
							   'user_name',
							   'user_id'
						   ]
					   )
					   ->from( 'user' )
					   ->where( [ 'user_name' => $batch ] )
					   ->caller( __METHOD__ )
					   ->fetchResultSet();

			foreach ( $res as $row ) {
				$result[$row->user_name] = (int)$row->user_id;
			}
		}

		return $result;
	}
}

// @codeCoverageIgnoreStart
return PopulateSecurePollVoterList::class;
// @codeCoverageIgnoreEnd
