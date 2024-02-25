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
 * @version 1.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\User\User;

class FixImageUser extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Fix image ownership of file' );

		$this->addOption( 'image-name', 'Name of the image you want to reassign image user.', true, true );
		$this->addArg( 'from', 'Old user to take edits from (can be ip too)' );
		$this->addArg( 'to', 'New user to give edits to' );
	}

	public function execute() {
		$wikiDB = $this->getDB( DB_PRIMARY );

		$from = $this->initializeUser( urldecode( $this->getArg( 0 ) ) );
		$to = $this->initializeUser( urldecode( $this->getArg( 1 ) ) );

		$imageName = urldecode( $this->getOption( 'image-name' ) );

		$pageId = $wikiDB->select(
			'page',
			'page_id',
			[
				'page_title' => $imageName,
			],
			__METHOD__
		);

		if ( !$pageId || !is_object( $pageId ) ) {
			$this->fatalError( '$pageId was not set to a valid array.' );
		}

		foreach ( $pageId as $id ) {
			$page_id = $id->page_id;

			$wikiDB->update(
				'revision',
				[ 'rev_actor' => $to->getActorId( $wikiDB ) ],
				[
					'rev_actor' => $from->getActorId(),
					'rev_page' => $page_id,
				],
				__METHOD__
			);

			$wikiDB->update(
				'image',
				[
					'img_actor' => $to->getActorId( $wikiDB ),
				],
				[
					'img_actor' => $from->getActorId(),
					'img_name' => $imageName,
				],
				__METHOD__
			);

			$this->output( "Reassigned image user from {$from} to {$to} on page id {$page_id}\n" );
		}
	}

	/**
	 * Initialize the user object
	 *
	 * @param string $username Username or IP address
	 * @return ?User
	 */
	private function initializeUser( $username ) {
		if ( $this->getServiceContainer()->getUserNameUtils()->isIP( $username ) ) {
			$user = new User();
			$user->setId( 0 );
			$user->setName( $username );
		} else {
			$user = $this->getServiceContainer()->getUserFactory()->newFromName( $username );
			if ( !$user ) {
				$this->fatalError( 'Invalid username' );
			}
		}
		$user->load();

		return $user;
	}
}

$maintClass = FixImageUser::class;
require_once RUN_MAINTENANCE_IF_MAIN;
