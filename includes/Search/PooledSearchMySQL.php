<?php

namespace Miraheze\MirahezeMagic\Search;

use MediaWiki\PoolCounter\PoolCounterWorkViaCallback;
use MediaWiki\Search\SearchMySQL;
use MediaWiki\Search\SqlSearchResultSet;
use MediaWiki\Status\Status;
use Throwable;

class PooledSearchMySQL extends SearchMySQL {

	private const string WIKI_POOL_TYPE = 'FullTextSearchWiki';
	private const string SERVER_POOL_TYPE = 'FullTextSearch';

	/**
	 * @param string $term
	 * @return SqlSearchResultSet|Status|null
	 */
	protected function doSearchTextInDB( $term ) {
		[ $wiki, $server ] = $this->getPoolKeys();

		$error = static fn ( Status $status ): Status => $status;

		$serverWork = new PoolCounterWorkViaCallback( self::SERVER_POOL_TYPE, $server, [
			'doWork' => fn () => parent::doSearchTextInDB( $term ),
			'error' => $error,
		] );

		$wikiWork = new PoolCounterWorkViaCallback( self::WIKI_POOL_TYPE, $wiki, [
			'doWork' => static fn () => $serverWork->execute(),
			'error' => $error,
		] );

		return $wikiWork->execute();
	}

	/**
	 * @return string[]
	 */
	private function getPoolKeys(): array {
		try {
			$db = $this->dbProvider->getReplicaDatabase();
			return [
				'search:wiki:' . $db->getDomainID(),
				'search:server:' . $db->getServerName(),
			];
		} catch ( Throwable ) {
			return [ 'search:wiki:unknown', 'search:server:unknown' ];
		}
	}
}
