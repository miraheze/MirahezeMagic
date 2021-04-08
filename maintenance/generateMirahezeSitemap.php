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
		$filePath = $config->get( 'UploadDirectory' ) . '/sitemaps';

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		if ( !file_exists( "/mnt/mediawiki-static/{$dbName}/sitemaps/" ) ) {
			Shell::command(
				'/bin/mkdir',
				'-p',
				$filePath
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		$wiki = new RemoteWiki( $dbName );
		$isPrivate = $wiki->isPrivate();
		if ( $isPrivate ) {
			$this->output( "Deleting sitemap for wiki {$dbName}\n" );

			Shell::command(
				'rm',
				'-rf',
				$filePath
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		} else {
			$this->output( "Generating sitemap for wiki {$dbName}\n" );

			// Remove old dump
			Shell::command(
				'rm',
				'-rf',
				"{$filePath}/**"
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();

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
				->limits( $limits )
				->execute();

			Shell::command(
				'/usr/bin/mv',
				"{$filePath}/sitemap-index-{$dbName}.xml",
				"{$filePath}/sitemap.xml"
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}
	}
}

$maintClass = 'GenerateMirahezeSitemap';
require_once RUN_MAINTENANCE_IF_MAIN;
