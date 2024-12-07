<?php

namespace Miraheze\MirahezeMagic\Jobs;

use Job;
use MediaWiki\MediaWikiServices;

class ClearGitInfoCache extends Job {

	public const JOB_NAME = 'ClearGitInfoCache';

	private array $keys;
	private string $startWiki;

	public function __construct( array $params ) {
		parent::__construct( self::JOB_NAME, $params );

		$this->startWiki = $params['startWiki'];
		$this->keys = $params['keys'];
	}

	public function run(): bool {
		global $wgDBname;

		$cache = MediaWikiServices::getInstance()->getObjectCacheFactory()->getInstance( CACHE_ANYTHING );

		foreach ( $this->keys as $key ) {
			$startWiki = $this->startWiki;
			$key = preg_replace( "/^$startWiki:/", "$wgDBname:", $key );
			$cache->delete( $key );
		}

		return true;
	}
}