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

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

class GenerateMirahezeSitemap extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Generates sitemap for all miraheze wikis (apart from private ones).";
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		$dbName = $config->get( 'DBname' );

		if ( !file_exists( "/mnt/mediawiki-static/{$dbName}/sitemaps/" ) ) {
			Shell::command( '/bin/mkdir', '-p', "/mnt/mediawiki-static/{$dbName}/sitemaps" )->execute();
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		$wiki = new RemoteWiki( $dbName );
		$isPrivate = $wiki->isPrivate();
		if ( $isPrivate ) {
			$this->output( "Deleting sitemap for wiki {$dbName}\n" );

			Shell::command(
				'rm',
				'-rf',
				"/mnt/mediawiki-static/{$dbName}/sitemaps"
			)
				->limits( $limits )
				->execute();
		} else {
			$this->output( "Generating sitemap for wiki {$dbName}\n" );

			// Remove old dump
			Shell::command(
				'rm',
				'-rf',
				"/mnt/mediawiki-static/{$dbName}/sitemaps/**"
			)
				->limits( $limits )
				->execute();

			// Generate new dump
			Shell::command(
				'/usr/bin/php',
				'/srv/mediawiki/w/maintenance/generateSitemap.php',
				'--fspath', 
				"/mnt/mediawiki-static/{$dbName}/sitemaps",
				'--urlpath',
				"/{$dbName}/sitemaps/",
				'--server',
				'https://static.miraheze.org',
				'--compress',
				'yes',
				'--wiki',
				$dbName
			)
				->limits( $limits )
				->execute();

			Shell::command(
				'/usr/bin/mv',
				"/mnt/mediawiki-static/{$dbName}/sitemaps/sitemap-index-{$dbName}.xml",
				"/mnt/mediawiki-static/{$dbName}/sitemaps/sitemap.xml"
			)
				->limits( $limits )
				->execute();
		}
	}
}

$maintClass = 'GenerateMirahezeSitemap';
require_once RUN_MAINTENANCE_IF_MAIN;
