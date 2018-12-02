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

class GenerateMirahezeSitemap extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Executes the generateSitemap.php script";
	}

	public function execute() {
		global $wgServer, $wgDBname;
		
		$this->output( "Generating sitemap for wiki {$wgDBname}" );

		exec( "/usr/bin/php /srv/mediawiki/w/maintenance/generateSitemap.php --fspath=/mnt/mediawiki-static/sitemaps/{$wgServer} --identifier={$wgDBname} --urlpath=https://{$wgServer}/ --server=https://{$wgServer} --compress=yes --wiki={$wgDBname}" );
	}
}

$maintClass = 'GenerateMirahezeSitemap';
require_once RUN_MAINTENANCE_IF_MAIN;
