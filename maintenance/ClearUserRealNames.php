<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\Maintenance\Maintenance;

use Wikimedia\Rdbms\IDatabase;

/**
 * Clears the `user_real_name` field from the database.
 * 
 * Written using [DeleteLocalPasswords.php](https://doc.wikimedia.org/mediawiki-core/1.46.1/php/DeleteLocalPasswords_8php_source.html)
 * as underlying reference.
 *
 * @since 1.46
 * @ingroup Maintenance
 */
class ClearUserRealNames extends Maintenance {
	protected int $total = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Deletes users' real names." );
		$this->setBatchSize( 500 );
		$this->addOption( "dry-run", "Do not make live changes" );
	}
	
	public function execute() {
		if ( $this->getOption( 'dry-run' )) {
			$this->output( "A dry run will be executed. No live changes will be made to the database.\n" );
    	}
    	foreach ( $this->getUserBatches() as $userBatch ) {
			$this->processUsers( $userBatch, $this->getUserDB() );
    	}
 
		$this->output( "done. (wrote $this->total rows)\n" );
	}

	protected function getUserDB() {
		return $this->getPrimaryDB();
	}

	protected function processUsers( array $userIds, IDatabase $dbw ) {
		if ( !$userIds ) {
			return;
		}
    	if ( $this->getOption( 'dry-run' )) {
			$this->total += count($userIds);
			$this->output( "[DRY RUN] Updated {$this->total} rows\n" );
		} else {
			$dbw->newUpdateQueryBuilder()
				->update( 'user' )
				->set( [ 'user_real_name' => '' ] )
				->where( [ 'user_id' => $userIds ] )
				->caller( __METHOD__ )->execute();
      		$this->total += $dbw->affectedRows();
      		$this->output( "Updated {$this->total} rows\n" );
      		$this->waitForReplication();
		}
	}

	protected function getUserBatches() {
		$lastUserId = -1;
		$dbw = $this->getUserDB();
		do {
			$this->output( "\t ... querying from '$lastUserId'\n" );
			$userIds = $dbw->newSelectQueryBuilder()
				->select( 'user_id' )
				->from( 'user' )
				->where( $dbw->expr( 'user_real_name', '!=', '' ) )
				->andWhere( $dbw->expr( 'user_id', '>', $lastUserId ) )
				->orderBy( 'user_id ASC' )
				->limit( $this->getBatchSize() )
				->caller( __METHOD__ )->fetchFieldValues();
      		if ( $userIds ) {
        		yield $userIds;
        		$lastUserId = end( $userIds );
      		}
    	} while ( count( $userIds ) === $this->getBatchSize() );
  	}
}

$maintClass = ClearUserRealNames::class;
