<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\Json\FormatJson;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class GetSiteInfo extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption( 'json', 'Output in JSON instead of the human-readable plain text format',
			false, false );

		$this->addDescription( 'Returns information about a wiki like sitename, URL, etc.' );
	}

	public function execute() {
		$language = $this->getConfig()->get( MainConfigNames::LanguageCode );
		$license = $this->getConfig()->get( MainConfigNames::RightsText );
		$sitename = $this->getConfig()->get( MainConfigNames::Sitename );
		$url = $this->getConfig()->get( MainConfigNames::Server );

		if ( $this->hasOption( 'json' ) ) {
			$json = [
				'sitename' => $sitename,
				'url' => $url,
				'language' => $language,
				'license' => $license,
			];
			$this->output( FormatJson::encode( $json ) );
		} else {
			$this->output( "Sitename: $sitename\nURL: $url\nLanguage: $language\nLicense: $license\n" );
		}
	}
}

// @codeCoverageIgnoreStart
return GetSiteInfo::class;
// @codeCoverageIgnoreEnd
