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
 * @author Universal Omega
 * @version 2.0
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class AssignImportedEdits extends Maintenance {
	private $wikiRevision = null;

	private $importPrefix = 'imported>';

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Assigns imported edits for users";
		$this->addOption( 'user', 'Username you want edits to be assigned to. (optional), Defaults to all usernames with import> prefix.', false, true );
		$this->addOption( 'no-run', 'Runs without assigning edits to users, useful for testing.', false, false );
		$this->addOption( 'import-prefix', 'This is the import prefix, defaults to \'imported\'.', false, false );
		$this->addOption( 'norc', 'Don\'t update the recent changes table', false, false );
	}

	public function execute() {
		$this->wikiRevision = wfGetDB( DB_PRIMARY );

		if ( $this->getOption( 'import-prefix' ) ) {
			$this->importPrefix = "{$this->getOption( 'import-prefix' )}>";
		}

		$res = $this->wikiRevision->select(
			'revision_actor_temp',
			'revactor_actor',
			[],
			__METHOD__,
			[ 'GROUP BY' => 'revactor_actor' ]
		);

		if ( !$res || !is_object( $res ) ) {
			throw new MWException( '$res was not set to a valid array.' );
		}

		foreach ( $res as $row ) {
			$userClass = new User;
			$user = $this->getOption( 'user' ) ? $userClass->newFromName( $this->getOption( 'user' ) ) : null;
			$actorName = $userClass->newFromActorId( $row->revactor_actor );
			$assignUserEdit = $userClass->newFromName( str_replace( $this->importPrefix, '', $actorName->getName() ) );

			if ( $user ) {
				$nameIsValid = $userClass->newFromName( $user )->getId();
				$name = $this->importPrefix . $user->getName();

				if ( strpos( $actorName->getName(), $this->importPrefix ) === 0 ) {
					if ( $nameIsValid !== 0 && $actorName->getName() === $name ) {
						$this->assignEdits( $actorName, $assignUserEdit );
					}
				}
			} else {
				$nameIsValid = $userClass->newFromName( str_replace( $this->importPrefix, '', $actorName->getName() ) );
				if ( strpos( $actorName->getName(), $this->importPrefix ) === 0 ) {
					if ( $nameIsValid->getId() !== 0 && $actorName ) {
						$this->assignEdits( $actorName, $assignUserEdit );
					}
				}
			}
		}
	}

	private function assignEdits( &$user, &$importUser ) {
		$this->output(
			"Assigning imported edits from " . ( strpos( $user, $this->importPrefix ) === false ? $this->importPrefix : null ) . "{$user->getName()} to {$importUser->getName()}\n"
		);

		$dbw = $this->getDB( DB_PRIMARY );
		$this->beginTransaction( $dbw, __METHOD__ );

		# Count things
		$this->output( "Checking current edits..." );
		$revQueryInfo = ActorMigration::newMigration()->getWhere( $dbw, 'rev_user', $user );
		$res = $dbw->select(
			[ 'revision' ] + $revQueryInfo['tables'],
			'COUNT(*) AS count',
			$revQueryInfo['conds'],
			__METHOD__,
			[],
			$revQueryInfo['joins']
		);
		$row = $dbw->fetchObject( $res );
		$cur = $row->count;
		$this->output( "found {$cur}.\n" );

		$this->output( "Checking deleted edits..." );
		$arQueryInfo = ActorMigration::newMigration()->getWhere( $dbw, 'ar_user', $user, false );
		$res = $dbw->select(
			[ 'archive' ] + $arQueryInfo['tables'],
			'COUNT(*) AS count',
			$arQueryInfo['conds'],
			__METHOD__,
			[],
			$arQueryInfo['joins']
		);
		$row = $dbw->fetchObject( $res );
		$del = $row->count;
		$this->output( "found {$del}.\n" );

		# Don't count recent changes if we're not supposed to
		if ( !$this->getOption( 'norc' ) ) {
			$this->output( "Checking recent changes..." );
			$rcQueryInfo = ActorMigration::newMigration()->getWhere( $dbw, 'rc_user', $user, false );
			$res = $dbw->select(
				[ 'recentchanges' ] + $rcQueryInfo['tables'],
				'COUNT(*) AS count',
				$rcQueryInfo['conds'],
				__METHOD__,
				[],
				$rcQueryInfo['joins']
			);
			$row = $dbw->fetchObject( $res );
			$rec = $row->count;
			$this->output( "found {$rec}.\n" );
		} else {
			$rec = 0;
		}

		$total = $cur + $del + $rec;
		$this->output( "\nTotal entries to change: {$total}\n" );

		if ( !$this->getOption( 'no-run' ) ) {
			if ( $total ) {
				# Assign edits
				$this->output( "\nAssigning current edits..." );
				$dbw->update(
					'revision_actor_temp',
					[ 'revactor_actor' => $importUser->getActorId( $dbw ) ],
					[ 'revactor_actor' => $user->getActorId() ],
					__METHOD__
				);
				$this->output( "done.\nAssigning deleted edits..." );
				$dbw->update( 'archive',
					[ 'ar_actor' => $importUser->getActorId( $dbw ) ],
					[ $arQueryInfo['conds'] ], __METHOD__ );
				$this->output( "done.\n" );
				# Update recent changes if required
				if ( !$this->getOption( 'norc' ) ) {
					$this->output( "Updating recent changes..." );
					$dbw->update( 'recentchanges',
						[ 'rc_actor' => $importUser->getActorId( $dbw ) ],
						[ $rcQueryInfo['conds'] ], __METHOD__ );
					$this->output( "done.\n" );
				}
			}
		}

		$this->commitTransaction( $dbw, __METHOD__ );

		return (int)$total;
	}
}

$maintClass = 'AssignImportedEdits';
require_once RUN_MAINTENANCE_IF_MAIN;
