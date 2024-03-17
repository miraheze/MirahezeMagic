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
 * @ingroup Maintenance
 * @author Paladox
 * @author Universal Omega
 * @version 2.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use GenerateSitemap;
use Maintenance;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;
use Miraheze\CreateWiki\RemoteWiki;

class GenerateMirahezeSitemap extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates sitemap for all Miraheze wikis (apart from private ones).' );
	}

	public function execute() {
		$localRepo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$backend = $localRepo->getBackend();

		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		$filePath = wfTempDir() . '/sitemaps';

		$wiki = new RemoteWiki(
			$dbname,
			$this->getServiceContainer()->get( 'CreateWikiHookRunner' )
		);

		$isPrivate = $wiki->isPrivate();
		if ( $isPrivate ) {
			$this->output( "Deleting sitemap for wiki {$dbname}\n" );

			$sitemaps = $backend->getTopFileList( [
				'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
				'adviseStat' => false,
			] );

			foreach ( $sitemaps as $sitemap ) {
				$status = $backend->quickDelete( [
					'src' => $localRepo->getZonePath( 'public' ) . '/sitemaps/' . $sitemap,
				] );

				if ( !$status->isOK() ) {
					$this->output( 'Failure in deleting sitemap ' . $sitemap . ': ' . Status::wrap( $status )->getWikitext() );
				}
			}

			$backend->clean( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
		} else {
			$this->output( "Generating sitemap for wiki {$dbname}\n" );

			// Remove old dump
			$sitemaps = $backend->getTopFileList( [
				'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
				'adviseStat' => false,
			] );

			foreach ( $sitemaps as $sitemap ) {
				$status = $backend->quickDelete( [
					'src' => $localRepo->getZonePath( 'public' ) . '/sitemaps/' . $sitemap,
				] );

				if ( !$status->isOK() ) {
					$this->output( 'Failure in deleting sitemap ' . $sitemap . ': ' . Status::wrap( $status )->getWikitext() );
				}
			}

			// Generate new dump
			$generateSitemap = $this->runChild(
				GenerateSitemap::class,
				MW_INSTALL_PATH . '/maintenance/generateSitemap.php'
			);

			$generateSitemap->setOption( 'fspath', $filePath );
			$generateSitemap->setOption( 'urlpath', '/sitemaps/' . $dbname . '/sitemaps/' );
			$generateSitemap->setOption( 'server', $this->getConfig()->get( MainConfigNames::Server ) );
			$generateSitemap->setOption( 'compress', 'yes' );
			$generateSitemap->execute();

			$backend->prepare( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
			foreach ( glob( $filePath . '/sitemap-*' . $dbname . '*' ) as $sitemap ) {
				$backend->quickStore( [
					'src' => $sitemap,
					'dst' => $localRepo->getZonePath( 'public' ) . '/sitemaps/' . basename( $sitemap ),
				] );

				// And now we remove the file from the temp directory
				unlink( $sitemap );
			}

			$backend->quickMove( [
				'src' => $localRepo->getZonePath( 'public' ) . '/sitemaps/sitemap-index-' . $dbname . '.xml',
				'dst' => $localRepo->getZonePath( 'public' ) . '/sitemaps/sitemap.xml',
			] );
		}
	}
}

$maintClass = GenerateMirahezeSitemap::class;
require_once RUN_MAINTENANCE_IF_MAIN;
