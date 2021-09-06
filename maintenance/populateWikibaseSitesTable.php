<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup MirahezeMagic
 * @author John Lewis
 * @author Paladox
 * @author Universal Omega
 * @version 2.0
 */

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

use MediaWiki\MediaWikiServices;
use Wikibase\Lib\Sites\SiteMatrixParser;
use Wikibase\Lib\Sites\SitesBuilder;

if ( !class_exists( SitesBuilder::class ) ) {
	require_once( __DIR__ . '/../../Wikibase/lib/includes/Sites/SitesBuilder.php' );
}

if ( !class_exists( SiteMatrixParser::class ) ) {
	require_once __DIR__ . '/../../Wikibase/lib/includes/Sites/SiteMatrixParser.php';
}

/**
 * Maintenance script for populating the Sites table from another wiki that runs the
 * SiteMatrix or WikiDiscover extensions.
 *
 * @license GPL-2.0-or-later
 * @author Daniel Kinzler
 * @author Katie Filbert < aude.wiki@gmail.com >
 * @author Paladox
 */
class PopulateWikibaseSitesTable extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populate the sites table from another wiki that runs the SiteMatrix or WikiDiscover extensions.' );

		$this->addOption( 'strip-protocols', 'Strip http/https from URLs to make them protocol relative.' );
		$this->addOption( 'force-protocol', 'Force a specific protocol for all URLs (like http/https).', false, true );
		
		$this->addOption( 'load-from', 'Full URL to the API of the wiki to fetch the site info from. '
				. 'Default is https://meta.miraheze.org/w/api.php', false, true );
		$this->addOption( 'script-path', 'Script path to use for wikis in the site matrix. '
				. ' (e.g. "/w/$1")', false, true );
		$this->addOption( 'article-path', 'Article path for wikis in the site matrix. '
				. ' (e.g. "/wiki/$1")', false, true );
		$this->addOption( 'site-group', 'Site group that this wiki is a member of.  Used to populate '
				. ' local interwiki identifiers in the site identifiers table.  If not set and --wiki'
				. ' is set, the script will try to determine which site group the wiki is part of'
				. ' and populate interwiki ids for sites in that group.', false, true );
		$this->addOption( 'valid-groups', 'A array of valid site link groups.', false, true );
		$this->addOption( 'wiki-list', 'A array of wikis to look for.', false, true );

		$this->addOption( 'no-expand-group', 'Do not expand site group codes in site matrix. '
				. ' By default, "wiki" is expanded to "wikipedia".' );

		$this->addOption( 'api-group', 'Either \'sitematrix\' or \'wikidiscover\'.', true, true, false, true );
	}

	public function execute() {
		$siteGroup = $this->getOption( 'site-group' );
		$wikiId = $this->getOption( 'wiki' );

		$groups = [
			'sitematrix' => [
				'wikibooks',
				'wikinews',
				'wikipedia',
				'wikiquote',
				'wikisource',
				'wikiversity',
				'wikivoyage',
				'wiktionary'
			],
			'wikidiscover' => [
				'miraheze'
			]
		];

		$apiUrl = [
			'sitematrix' => 'https://meta.wikimedia.org/w/api.php',
			'wikidiscover' => 'https://meta.miraheze.org/w/api.php'
		];

		$function = [
			'sitematrix' => 'getSiteMatrixData',
			'wikidiscover' => 'getWikiDiscoverData'
		];

		$validGroups = [];
		$sites = [];

		foreach ( $this->getOption( 'api-group' ) as $apiGroup ) {
			$validGroups += $this->getOption( 'valid-groups', $groups[$apiGroup] );

			try {
				$url = $this->getOption( 'load-from', $apiUrl[$apiGroup] );

				$useFunction = $function[$apiGroup];

				$sites += $this->$useFunction( $url );
			} catch ( MWException $e ) {
				$this->output( $e->getMessage() );
			}

			$this->output( "done.\n" );
		}

		$store = MediaWikiServices::getInstance()->getSiteStore();
		$sitesBuilder = new SitesBuilder( $store, $validGroups );
		$sitesBuilder->buildStore( $sites, $siteGroup, $wikiId );
	}

	/**
	 * @param string $url
	 *
	 * @throws MWException
	 * @return string
	 */
	protected function getWikiDiscoverData( $url ) {
		$url .= '?action=wikidiscover&format=json';

		$list = $this->getOption( 'wiki-list' );
		if ( $list ) {
			$url .= "&wdwikislist=$list";
		}

		$json = MediaWikiServices::getInstance()->getHttpRequestFactory()->get( $url, [ 'timeout' => 300 ] );

		if ( !$json ) {
			throw new MWException( "Got no data from $url\n" );
		}

		$sites = $this->sitesFromJson( $json );

		return $sites;
	}

	/**
	 * @param string $url
	 *
	 * @throws MWException
	 * @return string
	 */
	protected function getSiteMatrixData( $url ) {
		$stripProtocols = (bool)$this->getOption( 'strip-protocols', false );
		$forceProtocol = $this->getOption( 'force-protocol', null );
		$scriptPath = $this->getOption( 'script-path', '/w/$1' );
		$articlePath = $this->getOption( 'article-path', '/wiki/$1' );
		$expandGroup = !$this->getOption( 'no-expand-group', false );

		if ( $stripProtocols && is_string( $forceProtocol ) ) {
			$this->fatalError( 'You can\'t use both strip-protocols and force-protocol' );
		}

		$protocol = true;
		if ( $stripProtocols ) {
			$protocol = false;
		} elseif ( is_string( $forceProtocol ) ) {
			$protocol = $forceProtocol;
		}

		$url .= '?action=sitematrix&format=json';

		$json = MediaWikiServices::getInstance()->getHttpRequestFactory()->get( $url, [], __METHOD__ );

		if ( !$json ) {
			throw new MWException( "Got no data from $url\n" );
		}

		$siteMatrixParser = new SiteMatrixParser( $scriptPath, $articlePath,
				$protocol, $expandGroup );

		$sites = $siteMatrixParser->sitesFromJson( $json );

		return $sites;
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
			if ( isset( $groupData['private'] ) ) {
				continue;
			}

			if ( strlen( $groupData['dbname'] ) > 32 ) {
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
		$site->setGroup( 'miraheze' );
		$url = $siteData['url'];
		$site->setFilePath( $url . $this->getOption( 'script-path', '/w/$1' ) );
		$site->setPagePath( $url . $this->getOption( 'article-path', '/wiki/$1' ) );

		return $site;
	}
}

$maintClass = PopulateWikibaseSitesTable::class;
require_once RUN_MAINTENANCE_IF_MAIN;
