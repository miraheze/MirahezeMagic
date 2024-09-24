<?php

namespace Wikibase\Lib\Sites {
	use MediaWiki\Site\Site;
	use MediaWiki\Site\SiteStore;

	class SitesBuilder {

		/**
		 * @param SiteStore $store
		 * @param string[] $validGroups
		 */
		public function __construct( SiteStore $store, array $validGroups ) {
		}

		/**
		 * @param Site[] $sites
		 * @param string|null $siteGroup
		 * @param string|null $wikiId
		 */
		public function buildStore( array $sites, $siteGroup = null, $wikiId = null ) {
		}
	}
}
