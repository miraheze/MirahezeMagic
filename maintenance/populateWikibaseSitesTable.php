<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

if ( !class_exists( Wikibase\Lib\Sites\SitesBuilder::class ) ) {
	require_once '/srv/mediawiki/w/extensions/Wikibase/lib/includes/Sites/SitesBuilder.php';
}

/**
 * Maintenance script for populating the Sites table from another wiki that runs the
 * WikiDiscovery extension.
 *
 * @license GPL-2.0-or-later
 * @author Daniel Kinzler
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Paladox
 */
class PopulateWikibaseSitesTable extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populate the sites table from another wiki that runs the SiteMatrix extension' );

		$this->addOption( 'load-from', "Full URL to the API of the wiki to fetch the site info from. "
				. "Default is https://meta.miraheze.org/w/api.php", false, true );
		$this->addOption( 'script-path', 'Script path to use for wikis in the site matrix. '
				. ' (e.g. "/w/$1")', false, true );
		$this->addOption( 'article-path', 'Article path for wikis in the site matrix. '
				. ' (e.g. "/wiki/$1")', false, true );
		$this->addOption( 'site-group', 'Site group that this wiki is a member of.  Used to populate '
				. ' local interwiki identifiers in the site identifiers table.  If not set and --wiki'
				. ' is set, the script will try to determine which site group the wiki is part of'
				. ' and populate interwiki ids for sites in that group.', false, true );
		$this->addOption( 'valid-groups', 'A array of valid site link groups.', false, true );
	}

	public function execute() {
		$url = $this->getOption( 'load-from', 'https://meta.miraheze.org/w/api.php' );
		$siteGroup = $this->getOption( 'site-group' );
		$wikiId = $this->getOption( 'wiki' );

		$groups = [ 'wikipedia', 'wikivoyage', 'wikiquote', 'wiktionary',
			'wikibooks', 'wikisource', 'wikiversity', 'wikinews' ];
		$validGroups = $this->getOption( 'valid-groups', $groups );

		try {
			$json = $this->getWikiDiscoveryData( $url );

			$sites = $this->sitesFromJson( $json );

			$store = MediaWiki\MediaWikiServices::getInstance()->getSiteStore();
			$sitesBuilder = new Wikibase\Lib\Sites\SitesBuilder( $store, $validGroups );
			$sitesBuilder->buildStore( $sites, $siteGroup, $wikiId );

		} catch ( MWException $e ) {
			$this->output( $e->getMessage() );
		}

		$this->output( "done.\n" );
	}

	/**
	 * @param string $url
	 *
	 * @throws MWException
	 * @return string
	 */
	protected function getWikiDiscoveryData( $url ) {
		$url .= '?action=wikidiscover&format=json';

		$json = Http::get( $url );

		if ( !$json ) {
			throw new MWException( "Got no data from $url\n" );
		}

		return $json;
	}

	/**
	 * @param string $json
	 *
	 * @throws InvalidArgumentException
	 * @return Site[]
	 */
	public function sitesFromJson( $json ) {
		$specials = null;

		$data = json_decode( $json, true );

		if ( !is_array( $data ) || !array_key_exists( 'wikidiscover', $data ) ) {
			throw new InvalidArgumentException( 'Cannot decode site matrix data.' );
		}

		$groups = $data['wikidiscover'];

		$sites = [];

		foreach ( $groups as $groupData ) {
			if ( !array_key_exists( 'languagecode', $groupData ) ) {
				continue;
			}

			$sites = array_merge(
				$sites,
				$this->getSitesFromLangGroup( $groupData )
			);
		}

		return $sites;
	}

	/**
	 * Gets an array of Site objects for all sites of the same language
	 * subdomain grouping used in the site matrix.
	 *
	 * @param array $langGroup
	 *
	 * @return Site[]
	 */
	private function getSitesFromLangGroup( array $langGroup ) {
		$sites = [];

		$site = $this->getSiteFromSiteData( $langGroup );
		$site->setLanguageCode( $langGroup['languagecode'] );
		$siteId = $site->getGlobalId();
		$sites[$siteId] = $site;

		return $sites;
	}

	/**
	 * @param array $siteData
	 *
	 * @return Site
	 */
	private function getSiteFromSiteData( array $siteData ) {
		$site = new MediaWikiSite();
		$site->setGlobalId( $siteData['dbname'] );

		$siteGroup = $siteData['languagecode'];
		$site->setGroup( $siteGroup );

		$url = $siteData['url'];
		$url = preg_replace( '@^https?:@', '', $url );

		$site->setFilePath( $url . $this->getOption( 'script-path', '/w/$1' ) );

		$site->setPagePath( $url . $this->getOption( 'article-path', '/wiki/$1' ) );

		return $site;
	}
}

$maintClass = PopulateWikibaseSitesTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
