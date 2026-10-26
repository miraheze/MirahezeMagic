<?php

namespace Miraheze\MirahezeMagic;

use MediaWiki\FileRepo\ForeignAPIRepo;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;
use function array_filter;
use function array_values;
use function count;
use function is_array;
use function is_object;
use function ksort;
use function microtime;
use function mt_rand;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use const MW_VERSION;

class ForeignAPIRepoWithFixedUA extends ForeignAPIRepo {

	private const int API_TIMEOUT = 8;
	private const int API_CONNECT_TIMEOUT = 2;
	private const int FAILURE_WINDOW = 30;
	private const int FAILURE_THRESHOLD = 5;
	private const int BREAKER_OPEN_TIME = 60;
	private const int LOG_SAMPLE_RATE = 100;

	private ?string $apiBase;
	private ?string $apiProxy;

	/**
	 * @inheritDoc
	 */
	public function __construct( $info ) {
		parent::__construct( $info );
		$this->apiBase = $info['apibase'] ?? null;
		$this->apiProxy = $info['apiProxy'] ?? null;
	}

	public function getUserAgent(): string {
		$mediaWikiVersion = MW_VERSION;
		$wikiId = WikiMap::getCurrentWikiId();
		return "QuickInstantCommons/$mediaWikiVersion MediaWiki/$mediaWikiVersion $wikiId (https://miraheze.org; tech@miraheze.org)";
	}

	/**
	 * @inheritDoc
	 */
	public function fetchImageQuery( $query ) {
		if ( !isset( $query['uselang'] ) ) {
			$query['uselang'] = 'en';
		}

		ksort( $query );
		return parent::fetchImageQuery( $query );
	}

	/**
	 * @inheritDoc
	 */
	public function newFile( $title, $time = false ) {
		$file = parent::newFile( $title, $time );
		$this->ensureHandlerLanguage( $file );
		return $file;
	}

	/**
	 * @inheritDoc
	 */
	public function findFile( $title, $options = [] ) {
		$file = parent::findFile( $title, $options );
		$this->ensureHandlerLanguage( $file );
		return $file;
	}

	private function ensureHandlerLanguage( mixed $file ): void {
		if ( !is_object( $file ) || !$file->exists() ) {
			return;
		}

		$handler = $file->getHandler();
		if ( !$handler ) {
			return;
		}

		try {
			$handler->getLanguage();
		} catch ( RuntimeException ) {
			$handler->setLanguage(
				MediaWikiServices::getInstance()->getContentLanguage()
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function httpGet(
		$url, $timeout = 'default', $options = [], &$mtime = false
	) {
		$breakerKey = $this->wanCache->makeGlobalKey( 'filerepo-foreign-breaker', $this->getName() );
		if ( $this->wanCache->get( $breakerKey ) ) {
			return false;
		}

		$proxied = false;
		if ( $this->apiBase !== null && str_starts_with( $url, $this->apiBase ) ) {
			if ( $timeout === 'default' ) {
				$timeout = self::API_TIMEOUT;
			}

			if ( !isset( $options['connectTimeout'] ) ) {
				$options['connectTimeout'] = self::API_CONNECT_TIMEOUT;
			}

			if ( $this->apiProxy !== null ) {
				$url = $this->apiProxy . substr( $url, strlen( $this->apiBase ) );
				$options['noProxy'] = true;
				$proxied = true;
			}
		}

		$start = microtime( true );
		$result = parent::httpGet( $url, $timeout, $options, $mtime );
		$elapsedMs = (int)( ( microtime( true ) - $start ) * 1000 );

		if ( $result === false ) {
			$this->recordFailure( $breakerKey );
		}

		if ( mt_rand( 1, self::LOG_SAMPLE_RATE ) === 1 ) {
			LoggerFactory::getInstance( 'ForeignAPIRepo' )->info(
				'Foreign API fetch',
				[
					'url' => $url,
					'wiki' => WikiMap::getCurrentWikiId(),
					'ok' => $result !== false,
					'proxied' => $proxied,
					'ms' => $elapsedMs,
				]
			);
		}

		return $result;
	}

	private function recordFailure( string $breakerKey ): void {
		$failuresKey = $this->wanCache->makeGlobalKey( 'filerepo-foreign-failures', $this->getName() );
		$now = time();

		$recent = $this->wanCache->get( $failuresKey );
		if ( !is_array( $recent ) ) {
			$recent = [];
		}

		$recent = array_values( array_filter(
			$recent,
			static fn ( int $time ): bool => $time > $now - self::FAILURE_WINDOW
		) );
		$recent[] = $now;

		$this->wanCache->set( $failuresKey, $recent, self::FAILURE_WINDOW );

		if ( count( $recent ) >= self::FAILURE_THRESHOLD ) {
			$this->wanCache->set( $breakerKey, 1, self::BREAKER_OPEN_TIME );
			LoggerFactory::getInstance( 'ForeignAPIRepo' )->warning(
				'Foreign API breaker opened',
				[ 'repo' => $this->getName(), 'failures' => count( $recent ) ]
			);
		}
	}
}
