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

class AssignImportedEdits extends Maintenance {
	private $wikiRevision = null;

	private $importPrefix = 'imported>';

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Re assigns imported edits for users";
		$this->addOption( 'user', 'Username you want edits to be assigned to. (optional), Defaults to all usernames with import> prefix.', false, true );
		$this->addOption( 'no-run', 'Runs without assigning edits to users, useful for testing.', false, false );
		$this->addOption( 'import-prefix', 'This is the import prefix, defaults to imported>', false, false );
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
			$user = $this->getOption( 'user' );
			if ( $user ) {
				$nameIsValid = User::newFromName( $user )->getId();
				$name = $this->importPrefix . $user;
				if ( strpos( $row->rev_user_text, chr($this->importPrefix) ) === 0 ) {
					if ( $nameIsValid !== 0 && $row->rev_user_text === $name ) {
						$this->assignEdit( $name );
					}
				}
			} else {
				$user = $row->rev_user_text;
				$nameIsValid = User::newFromName( str_replace( $this->importPrefix, '', $user ) );
				if ( strpos( $user, chr($this->importPrefix) ) === 0 ) {
					if ( $nameIsValid && $user ) {
						$this->assignEdit( $user );
					}
				}
			}
		}
	}

	private function assignEdit( $user ) {
		$assignUserEdit = str_replace( $this->importPrefix , '', $user );
		$this->output( "Assinging import edits from {$user} to {$assignUserEdit}\n");

		if ( $this->getOption( 'no-run' ) ) {
			return;
		}

		$this->wikiRevision->update(
			'revision',
			[
				'rev_user_text' => $assignUserEdit,
			],
			[
				'rev_user_text' => $user,
			],
			__METHOD__
		);
	}
}

$maintClass = 'AssignImportedEdits';
require_once RUN_MAINTENANCE_IF_MAIN;
