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
* @author Southparkfan
* @author John Lewis
* @author Paladox
* @version 1.0
*/

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class CreateUsers extends Maintenance {
	private $wikiRevision = null;

	private $importPrefix = '';

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Creates users accounts - useful if you have imported a wiki, and a user account for a revision did not exist.";
		$this->addOption( 'import-prefix', 'This is the import prefix for the username (in revision table), defaults to empty string', false, false );
	}

	public function execute() {
		$this->wikiRevision = wfGetDB( DB_MASTER );

		if ( $this->getOption( 'import-prefix' ) ) {
			$this->importPrefix = $this->getOption( 'import-prefix' );
		}

		$res = $this->wikiRevision->select(
			'revision',
			'rev_user_text',
			array(),
			__METHOD__,
			[ 'GROUP BY' => 'rev_user_text' ]
		);

		if ( !$res || !is_object( $res ) ) {
			throw new MWException( '$res was not set to a valid array.' );
		}

		foreach ( $res as $row ) {
			$user = $row->rev_user_text;
			if ( !User::isIP( $user ) ) {
				$name = str_replace( $this->importPrefix, '', $user );
				if ( $this->importPrefix === '' ) {
					if ( $name ) {
						$this->createUser( $name );
					}
				} else {
					if ( strpos( $user, $this->importPrefix ) === 0 ) {
						if ( $name ) {
							$this->createUser( $name );
						}
					}
				}
			}
		}
	}

	private function createUser( $name ) {
		global $wgDBname;

		$user = User::createNew( $name );
		if ( $user !== null ) {
		  $this->output( "Created local {$user} on wiki {$wgDBname}\n");
		}

		$cau = new CentralAuthUser( $name, 0 );
		$create = $cau->promoteToGlobal( $wgDBname );

		if ( $create->isGood() ) {
		  $this->output( "Created global {$user}\n");
		}

		if ( $this->getOption( 'no-run' ) ) {
			return;
		}
	}
}

$maintClass = 'CreateUsers';
require_once RUN_MAINTENANCE_IF_MAIN;
