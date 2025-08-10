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
 * @author Universal Omega
 * @version 1.0
 */

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\WikiMap\WikiMap;

class InsertMissingLocalUserRows extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addOption( 'dry-run', 'Simulate the operation without actually inserting the rows' );
		$this->addOption( 'all-wikis', 'Run on all wikis present in $wgLocalDatabases' );
	}

	public function execute() {
		$dryRun = $this->getOption( 'dry-run', false );
		$all = $this->getOption( 'all-wikis', false );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$centralDB = $connectionProvider->getReplicaDatabase( $this->getConfig()->get( 'CentralAuthDatabase' ) );

		foreach ( ( $all ? $this->getConfig()->get( MainConfigNames::LocalDatabases ) : [ WikiMap::getCurrentWikiId() ] ) as $wiki ) {
			$res = $connectionProvider->getReplicaDatabase( $wiki )->select(
				'user',
				[ 'user_name' ],
				[
					'user_name != "CreateWiki AI"',
					'user_name != "CreateWiki Extension"',
					'user_name != "RequestCustomDomain Extension"',
					'user_name != "RequestCustomDomain Status Update"',
					'user_name != "ImportDump Extension"',
					'user_name != "ImportDump Status Update"',
					'user_name != "Global rename script"',
					'user_name != "⧼abusefilter-blocker⧽"',
					'user_name != "MediaWiki default"',
					'user_name != "New user message"',
					'user_name != "Babel AutoCreate"',
					'user_name != "FuzzyBot"',
					'user_name != "Maintenance script"',
					'user_name != "MediaWiki message delivery"',
					'user_name != "DynamicPageList3 extension"',
					'user_name != "Flow talk page manager"',
					'user_name != "Abuse filter"',
					'user_name != "ModerationUploadStash"',
					'user_name != "Delete page script"',
					'user_name != "Move page script"'
				],
				__METHOD__
			);

			foreach ( $res as $row ) {
				$exists = $centralDB->selectRowCount(
					'localuser',
					'*',
					[
						'lu_name' => $row->user_name,
						'lu_wiki' => $wiki,
					],
					__METHOD__,
					[ 'LIMIT' => 1 ]
				);

				if ( !$exists ) {
					$centralAuthUser = new CentralAuthUser( $row->user_name, CentralAuthUser::READ_LATEST );

					if ( $dryRun ) {
						$this->output( "Would insert row for user {$row->user_name} on wiki {$wiki}\n" );
					} else {
						$centralAuthUser->attach( $wiki, 'login', false, 0 );
					}
				}
			}
		}
	}
}

// @codeCoverageIgnoreStart
return InsertMissingLocalUserRows::class;
// @codeCoverageIgnoreEnd
