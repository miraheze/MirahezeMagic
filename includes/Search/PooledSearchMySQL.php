<?php

namespace Miraheze\MirahezeMagic\Search;

use MediaWiki\PoolCounter\PoolCounterWorkViaCallback;
use MediaWiki\Search\SearchMySQL;
use MediaWiki\Search\SqlSearchResultSet;
use MediaWiki\Status\Status;
use Throwable;

class PooledSearchMySQL extends SearchMySQL {

	private const string POOL_TYPE = 'FullTextSearch';

	/**
	 * @param string $term
	 * @return SqlSearchResultSet|Status|null
	 */
	protected function doSearchTextInDB( $term ) {
		$work = new PoolCounterWorkViaCallback( self::POOL_TYPE, $this->getPoolKey(), [
			'doWork' => fn () => parent::doSearchTextInDB( $term ),
			'error' => static fn ( Status $status ): Status => $status,
		] );

		return $work->execute();
	}

	private function getPoolKey(): string {
		try {
			$server = $this->dbProvider->getReplicaDatabase()->getServerName();
		} catch ( Throwable ) {
			$server = 'unknown';
		}

		return 'search:' . $server;
	}
}
