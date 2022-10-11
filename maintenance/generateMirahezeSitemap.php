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
 * @ingroup Maintenance
 * @author Paladox
 * @version 1.0
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use Miraheze\CreateWiki\RemoteWiki;

class GenerateMirahezeSitemap extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates sitemap for all miraheze wikis (apart from private ones).' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		$localRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$backend = $localRepo->getBackend();

		$dbName = $config->get( 'DBname' );
		$filePath = wfTempDir() . '/sitemaps';

		$wiki = new RemoteWiki( $dbName );
		$isPrivate = $wiki->isPrivate();
		if ( $isPrivate ) {
			$this->output( "Deleting sitemap for wiki {$dbName}\n" );

			$sitemaps = $backend->getFileList( [
				'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
				'topOnly' => true,
				'adviseStat' => false,
			] );

			foreach ( $sitemaps as $file ) {
				$status = $backend->quickDelete( [
					'src' => $localRepo->getZonePath( 'public' ) . '/' . $file,
				] );

				if ( !$status->isOK() ) {
					$this->output( 'Failure in deleting sitemap ' . $file . ': ' . Status::wrap( $status )->getWikitext() );
				}
			}

			$backend->clean( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
		} else {
			$this->output( "Generating sitemap for wiki {$dbName}\n" );

			// Remove old dump
			$sitemaps = $backend->getFileList( [
				'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
				'topOnly' => true,
				'adviseStat' => false,
			] );

			foreach ( $sitemaps as $file ) {
				$status = $backend->quickDelete( [
					'src' => $localRepo->getZonePath( 'public' ) . '/' . $file,
				] );

				if ( !$status->isOK() ) {
					$this->output( 'Failure in deleting sitemap ' . $file . ': ' . Status::wrap( $status )->getWikitext() );
				}
			}

			// Generate new dump
			Shell::command(
				'/usr/bin/php',
				'/srv/mediawiki/w/maintenance/generateSitemap.php',
				'--fspath',
				$filePath,
				'--urlpath',
				"/sitemaps/{$dbName}/sitemaps/",
				'--server',
				$config->get( 'Server' ),
				'--compress',
				'yes',
				'--wiki',
				$dbName
			)
				->restrict( Shell::RESTRICT_NONE )
				->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )
				->execute();

			$backend->prepare( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
			foreach ( glob( $filePath . '/sitemap-*' . $dbName . '*' ) as $sitemap ) {
				$backend->quickStore( [
					'src' => $sitemap,
					'dst' => $localRepo->getZonePath( 'public' ) . '/sitemaps/' . basename( $sitemap ),
				] );

				// And now we remove the file from the temp directory
				unlink( $sitemap );
			}

			$backend->quickMove( [
				'src' => $localRepo->getZonePath( 'public' ) . '/sitemaps/sitemap-index-' . $dbName . '.xml',
				'dst' => $localRepo->getZonePath( 'public' ) . '/sitemaps/sitemap.xml',
			] );
		}
	}
}

$maintClass = 'GenerateMirahezeSitemap';
require_once RUN_MAINTENANCE_IF_MAIN;
