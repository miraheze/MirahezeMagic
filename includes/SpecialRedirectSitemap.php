<?php

use MediaWiki\MediaWikiServices;

/**
 * Special Page for redirecting to a sitemap
 *
 * @author Paladox
 */
class SpecialRedirectSitemap extends SpecialPage {

	public function __construct() {
		parent::__construct( 'RedirectSitemap' );
	}

	public function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$this->checkPermissions();

		$out = $this->getOutput();

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		// Redirect to Sitemap
		header( "Location: https://static.miraheze.org/{$config->get( 'DBname' )}/sitemaps/sitemap.xml" );
	}

	protected function getGroupName() {
		return 'wiki';
	}
}
