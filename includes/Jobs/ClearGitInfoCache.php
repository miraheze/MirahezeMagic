<?php

namespace Miraheze\MirahezeMagic\Jobs;

use GenericParameterJob;
use Job;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;

class ClearGitInfoCache extends Job implements GenericParameterJob {

	public const JOB_NAME = 'ClearGitInfoCache';

	private array $keys;
	private string $startWiki;

	public function __construct( array $params ) {
		parent::__construct( self::JOB_NAME, $params );

		$this->startWiki = $params['startWiki'];
		$this->keys = $params['keys'];
	}

	public function run(): bool {
		$mediawikiServices = MediaWikiServices::getInstance();
		$cache = $mediawikiServices->getObjectCacheFactory()->getInstance( CACHE_ANYTHING );

		$startWiki = preg_quote( $this->startWiki, '/' );
		foreach ( $mediawikiServices->getMainConfig()->get( MainConfigNames::LocalDatabases ) as $db ) {
			foreach ( $this->keys as $key ) {
				$key = preg_replace( "/^$startWiki:/", "$db:", $key );
				$cache->delete( $key );
			}
		}

		return true;
	}
}
