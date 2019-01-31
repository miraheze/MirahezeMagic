<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

if ( !class_exists( Wikibase\Lib\Sites\SitesBuilder::class ) ) {
	require_once __DIR__ . '/srv/mediawiki/w/extensions/wikibase/lib/includes/Sites/SitesBuilder.php';
}

if ( !class_exists(  Wikibase\Lib\Sites\SiteMatrixParser::class ) ) {
	require_once __DIR__ . '/srv/mediawiki/w/extensions/wikibase/lib/includes/Sites/SiteMatrixParser.php';
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
class PopulateWikiDiscoverySitesTable extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populate the sites table from another wiki that runs the SiteMatrix extension' );

		$this->addOption( 'load-from', "Full URL to the API of the wiki to fetch the site info from. "
				. "Default is https://meta.miraheze.org/w/api.php", false, true );
		$this->addOption( 'site-group', 'Site group that this wiki is a member of.  Used to populate '
				. ' local interwiki identifiers in the site identifiers table.  If not set and --wiki'
				. ' is set, the script will try to determine which site group the wiki is part of'
				. ' and populate interwiki ids for sites in that group.', false, true );
		$this->addOption( 'valid-groups', 'A array of valid site link groups.', true );
	}

	public function execute() {
		$url = $this->getOption( 'load-from', 'https://meta.miraheze.org/w/api.php' );
		$siteGroup = $this->getOption( 'site-group' );
		$wikiId = $this->getOption( 'wiki' );

		$validGroups = [ 'wikipedia', 'wikivoyage', 'wikiquote', 'wiktionary',
			'wikibooks', 'wikisource', 'wikiversity', 'wikinews' ];
		$url = $this->getOption( 'valid-groups', $validGroups );

		try {
			
			$json = $this->getWikiDiscoveryData( $url );

			$sites = json_decode( $json, true );
			
			if ( !is_array( $sites ) || !array_key_exists( 'wikidiscover', $sites ) ) {
				throw new InvalidArgumentException( 'Cannot decode wiki discovery data.' );
			}

			$store = MediaWikiServices::getInstance()->getSiteStore();
			$sitesBuilder = new use Wikibase\Lib\Sites\SitesBuilder( $store, $validGroups );
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

}

$maintClass = PopulateWikiDiscoverySitesTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
