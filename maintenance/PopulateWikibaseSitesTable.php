<?php

namespace Miraheze\MirahezeMagic\Maintenance;

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

use Exception;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Site\Site;
use Wikibase\Lib\Sites\SitesBuilder;

if ( !class_exists( SitesBuilder::class ) ) {
	require_once MW_INSTALL_PATH . '/extensions/Wikibase/lib/includes/Sites/SitesBuilder.php';
}

class PopulateWikibaseSitesTable extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populate the Sites table from another wiki that runs the WikiDiscover extension.' );

		$this->addOption( 'load-from', "Full URL to the API of the wiki to fetch the site info from. "
				. "Default is https://meta.miraheze.org/w/api.php", false, true );
		$this->addOption( 'script-path', 'Script path to use for wikis in WikiDiscover. '
				. ' (e.g. "/w/$1")', false, true );
		$this->addOption( 'article-path', 'Article path for wikis in WikiDiscover. '
				. ' (e.g. "/wiki/$1")', false, true );
		$this->addOption( 'site-group', 'Site group that this wiki is a member of.  Used to populate '
				. ' local interwiki identifiers in the site identifiers table.  If not set and --wiki'
				. ' is set, the script will try to determine which site group the wiki is part of'
				. ' and populate interwiki ids for sites in that group.', false, true );
		$this->addOption( 'valid-groups', 'A array of valid site link groups.', false, true );
		$this->addOption( 'all-wikis', 'Populates the Sites table for all wikis present in $wgLocalDatabases.' );
	}

	public function execute(): void {
		$url = $this->getOption( 'load-from', 'https://meta.miraheze.org/w/api.php' );
		$siteGroup = $this->getOption( 'site-group' );
		$allWikis = $this->hasOption( 'all-wikis' );
		$wikis = $allWikis ?
			$this->getConfig()->get( MainConfigNames::LocalDatabases ) :
			[ $this->getConfig()->get( MainConfigNames::DBname ) ];

		$groups = [ 'miraheze' ];
		$validGroups = $this->getOption( 'valid-groups', $groups );

		try {
			$data = $this->getWikiDiscoverData( $url );
			$sites = $this->sitesFromData( $data );

			$store = $this->getServiceContainer()->getSiteStore();
			$sitesBuilder = new SitesBuilder( $store, $validGroups );
			foreach ( $wikis as $wikiId ) {
				// Skip wikis not in API response
				if ( $allWikis && !in_array( $wikiId, array_column( $data, 'dbname' ), true ) ) {
					continue;
				}

				$this->output( "Building sites store for $wikiId\n" );
				$sitesBuilder->buildStore( $sites, $siteGroup, $wikiId );
			}
		} catch ( Exception $e ) {
			$this->fatalError( $e->getMessage() );
		}

		$this->output( "done.\n" );
	}

	private function getWikiDiscoverData( string $baseUrl ): array {
		$offset = 0;
		$allWikis = [];

		do {
			$this->output( "Fetching sites with offset: $offset\n" );
			$url = wfAppendQuery( $baseUrl, [
				'action' => 'query',
				'format' => 'json',
				'list' => 'wikidiscover',
				'wdlimit' => 500,
				'wdprop' => 'languagecode|url',
				'wdstate' => 'public|undeleted',
				'wdoffset' => $offset,
			] );

			$json = $this->getServiceContainer()->getHttpRequestFactory()->get(
				$url,
				[ 'timeout' => 300 ],
				__METHOD__
			);

			if ( !$json ) {
				$json = '';
				$this->fatalError( "Got no data from $url" );
			}

			$data = json_decode( $json, true );
			if (
				!is_array( $data ) ||
				!isset( $data['query']['wikidiscover'] )
			) {
				$this->fatalError( 'Cannot decode WikiDiscover data.' );
			}

			$wikis = $data['query']['wikidiscover']['wikis'] ?? [];
			$allWikis = array_merge(
				$allWikis,
				is_array( $wikis ) ? array_values( $wikis ) : []
			);

			$count = $data['query']['wikidiscover']['count'] ?? 0;
			$offset += count( $wikis );

		} while ( $count > 0 );

		if ( !$allWikis ) {
			$this->fatalError( 'No wikis found' );
		}

		return $allWikis;
	}

	/**
	 * @param array $data
	 * @return Site[]
	 */
	private function sitesFromData( array $data ): array {
		$sites = [];
		foreach ( $data as $groupData ) {
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
	 * subdomain grouping used in WikiDiscover.
	 *
	 * @param array $langGroup
	 * @return Site[]
	 */
	private function getSitesFromLangGroup( array $langGroup ): array {
		$sites = [];

		$site = $this->getSiteFromSiteData( $langGroup );
		$site->setLanguageCode( $langGroup['languagecode'] );
		$siteId = $site->getGlobalId();
		$sites[$siteId] = $site;

		return $sites;
	}

	private function getSiteFromSiteData( array $siteData ): Site {
		$site = new MediaWikiSite();
		$site->setGlobalId( $siteData['dbname'] );
		$site->setGroup( 'miraheze' );
		$url = $siteData['url'];
		$site->setFilePath( $url . $this->getOption( 'script-path', '/w/$1' ) );
		$site->setPagePath( $url . $this->getOption( 'article-path',
			$this->getConfig()->get( 'Conf' )->get(
				'wgArticlePath', $siteData['dbname']
			)
		) );

		return $site;
	}
}

// @codeCoverageIgnoreStart
return PopulateWikibaseSitesTable::class;
// @codeCoverageIgnoreEnd
