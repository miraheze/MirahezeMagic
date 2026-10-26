<?php

namespace Miraheze\MirahezeMagic;

use MediaWiki\FileRepo\ForeignAPIRepo;
use MediaWiki\WikiMap\WikiMap;
use function ksort;
use function str_starts_with;
use function strlen;
use function substr;
use const MW_VERSION;

class ForeignAPIRepoWithFixedUA extends ForeignAPIRepo {

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
	public function httpGet(
		$url, $timeout = 'default', $options = [], &$mtime = false
	) {
		if (
			$this->apiProxy !== null &&
			$this->apiBase !== null &&
			str_starts_with( $url, $this->apiBase )
		) {
			$url = $this->apiProxy . substr( $url, strlen( $this->apiBase ) );
			$options['noProxy'] = true;
		}

		return parent::httpGet( $url, $timeout, $options, $mtime );
	}
}
