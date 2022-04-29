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
 * @version 3.0
 */

use MediaWiki\MediaWikiServices;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class AssignImportedEdits extends Maintenance {
	private $importPrefix = 'imported>';

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Assigns imported edits for users";
		$this->addOption( 'to', 'Username you want edits to be assigned to. (optional), Defaults to all usernames with import> prefix.', false, true );
		$this->addOption( 'from', 'Username you want edits to be assigned from. (optional), Username excluding prefix.', false, true );
		$this->addOption( 'no-run', 'Runs without assigning edits to users, useful for testing.', false, false );
		$this->addOption( 'import-prefix', 'This is the import prefix, defaults to \'imported\'.', false, false );
		$this->addOption( 'norc', 'Don\'t update the recent changes table', false, false );
	}

	public function execute() {
		$dbr = wfGetDB( DB_REPLICA );

		if ( $this->getOption( 'import-prefix' ) ) {
			$this->importPrefix = "{$this->getOption( 'import-prefix' )}>";
		}

		if ( $this->getOption( 'from' ) ) {
			$from = $this->importPrefix . $this->getOption( 'from' );
			$row = $dbr->selectRow(
				'actor',
				'actor_id',
				[
					'actor_name' => $from,
				],
				__METHOD__
			);

			if ( !$row ) {
				$this->output( 'Invalid \'from\' user.' );
				return;
			}

			$fromUser = User::newFromActorId( $row->actor_id );

			if ( !$fromUser ) {
				$this->output( 'Invalid \'from\' user.' );
				return;
			}

			$fromName = $fromUser->getName();

			$toUser = User::newFromName(
				$this->getOption( 'to' ) ?: str_replace(
					$this->importPrefix, '', $fromName
				)
			);

			if ( !$toUser ) {
				$this->output( 'Invalid \'to\' user.' );
				return;
			}

			$this->assignEdits( $fromUser, $toUser );

			return;
		}

		$res = $dbr->select(
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
			$fromUser = User::newFromActorId( $row->revactor_actor );

			if ( !$fromUser ) {
				$this->output( 'Invalid \'from\' user.' );
				return;
			}

			$fromName = $fromUser->getName();

			$toUser = User::newFromName(
				$this->getOption( 'to' ) ?: str_replace(
					$this->importPrefix, '', $fromName
				)
			);

			if ( !$toUser ) {
				$this->output( 'Invalid \'to\' user.' );
				return;
			}

			if ( strpos( $fromName, $this->importPrefix ) === 0 ) {
				if ( $toUser->getId() !== 0 ) {
					$this->assignEdits( $fromUser, $toUser );
				}
			}
		}
	}

	private function assignEdits( $user, $importUser ) {
		$this->output(
			"Assigning imported edits from " . ( strpos( $user, $this->importPrefix ) === false ? $this->importPrefix : null ) . "{$user->getName()} to {$importUser->getName()}\n"
		);

		$actorTableSchemaMigrationStage = $this->getConfig()->get( 'ActorTableSchemaMigrationStage' );
		$dbw = $this->getDB( DB_PRIMARY );
		$this->beginTransaction( $dbw, __METHOD__ );
		$actorNormalization = MediaWikiServices::getInstance()->getActorNormalization();
		$fromActorId = $actorNormalization->findActorId( $user, $dbw );

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
		$res = $dbw->select(
			[ 'archive' ],
			'COUNT(*) AS count',
			[ 'ar_actor' => $fromActorId ],
			__METHOD__
		);
		$row = $dbw->fetchObject( $res );
		$del = $row->count;
		$this->output( "found {$del}.\n" );

		# Don't count recent changes if we're not supposed to
		if ( !$this->getOption( 'norc' ) ) {
			$this->output( "Checking recent changes..." );
			$res = $dbw->select(
				[ 'recentchanges' ],
				'COUNT(*) AS count',
				[ 'rc_actor' => $fromActorId ],
				__METHOD__
			);
			$row = $dbw->fetchObject( $res );
			$rec = $row->count;
			$this->output( "found {$rec}.\n" );
		} else {
			$rec = 0;
		}

		$total = $cur + $del + $rec;
		$this->output( "\nTotal entries to change: {$total}\n" );

		$toActorId = $actorNormalization->acquireActorId( $importUser, $dbw );

		if ( !$this->getOption( 'no-run' ) ) {
			if ( $total ) {
				# Assign edits
				$this->output( "\nAssigning current edits..." );
				if ( $actorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_TEMP ) {
					$dbw->update(
						'revision_actor_temp',
						[ 'revactor_actor' => $toActorId ],
						[ 'revactor_actor' => $fromActorId ],
						__METHOD__
					);
				}
				if ( $actorTableSchemaMigrationStage & SCHEMA_COMPAT_WRITE_NEW ) {
					$dbw->update(
						'revision',
						[ 'rev_actor' => $toActorId ],
						[ 'rev_actor' => $fromActorId ],
						__METHOD__
					);
				}
				$this->output( "done.\nAssigning deleted edits..." );
				$dbw->update( 'archive',
					[ 'ar_actor' => $toActorId ],
					[ 'ar_actor' => $fromActorId ],
					__METHOD__
				);
				$this->output( "done.\n" );
				# Update recent changes if required
				if ( !$this->getOption( 'norc' ) ) {
					$this->output( "Updating recent changes..." );
					$dbw->update( 'recentchanges',
						[ 'rc_actor' => $toActorId ],
						[ 'rc_actor' => $fromActorId ],
						__METHOD__
					);
					$this->output( "done.\n" );
				}
			}
		}

		$this->commitTransaction( $dbw, __METHOD__ );

		return (int)$total;
	}
}

$maintClass = AssignImportedEdits::class;
require_once RUN_MAINTENANCE_IF_MAIN;
