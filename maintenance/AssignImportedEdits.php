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
 * @author Southparkfan
 * @author John Lewis
 * @author Paladox
 * @author Universal Omega
 * @version 5.0
 */

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\User;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

class AssignImportedEdits extends Maintenance {

	private string $importPrefix = 'imported>';

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Assigns imported edits for users.' );

		$this->addOption( 'to', 'Username you want edits to be assigned to. (optional), Defaults to all usernames with import prefix.', withArg: true );
		$this->addOption( 'from', 'Username you want edits to be assigned from. (optional), Username excluding prefix.', withArg: true );
		$this->addOption( 'no-run', 'Runs without assigning edits to users, useful for testing.' );
		$this->addOption( 'import-prefix', 'This is the import prefix, defaults to \'imported\'.', withArg: true );
		$this->addOption( 'norc', 'Don\'t update the recent changes table' );
	}

	public function execute(): void {
		$dbr = $this->getReplicaDB();
		if ( $this->hasOption( 'import-prefix' ) ) {
			$this->importPrefix = "{$this->getOption( 'import-prefix' )}>";
		}

		$userFactory = $this->getServiceContainer()->getUserFactory();
		if ( $this->hasOption( 'from' ) ) {
			$from = $this->importPrefix . $this->getOption( 'from' );
			$actorId = $dbr->newSelectQueryBuilder()
				->select( 'actor_id' )
				->from( 'actor' )
				->where( [ 'actor_name' => $from ] )
				->caller( __METHOD__ )
				->fetchField();

			if ( !$actorId ) {
				$this->fatalError( 'Invalid \'from\' user.' );
			}

			$fromUser = $userFactory->newFromActorId( $actorId );
			$fromName = $fromUser->getName();
			$toUser = $userFactory->newFromName(
				$this->getOption( 'to' ) ?: str_replace(
					$this->importPrefix, '', $fromName
				)
			);

			if ( !$toUser ) {
				$this->fatalError( 'Invalid \'to\' user.' );
			}

			$this->assignEdits( $fromUser, $toUser );
			return;
		}

		$actorIds = $dbr->newSelectQueryBuilder()
			->select( 'rev_actor' )
			->from( 'revision' )
			->groupBy( 'rev_actor' )
			->caller( __METHOD__ )
			->fetchFieldValues();

		if ( !$actorIds ) {
			$this->fatalError( 'No actors found.' );
		}

		foreach ( $actorIds as $actorId ) {
			$fromUser = $userFactory->newFromActorId( $actorId );
			$fromName = $fromUser->getName();
			$toUser = $userFactory->newFromName(
				$this->getOption( 'to' ) ?: str_replace(
					$this->importPrefix, '', $fromName
				)
			);

			if ( !$toUser ) {
				continue;
			}

			if ( str_starts_with( $fromName, $this->importPrefix ) ) {
				if ( $toUser->getId() !== 0 ) {
					$this->assignEdits( $fromUser, $toUser );
				}
			}
		}
	}

	private function assignEdits( User $user, User $importUser ): int {
		$this->output( 'Assigning imported edits from ' .
			( !str_contains( $user->getName(), $this->importPrefix ) ? $this->importPrefix : '' ) .
			"{$user->getName()} to {$importUser->getName()}\n"
		);

		$dbw = $this->getPrimaryDB();
		$this->beginTransactionRound( __METHOD__ );
		$actorNormalization = $this->getServiceContainer()->getActorNormalization();
		$fromActorId = $actorNormalization->findActorId( $user, $dbw );

		// Count things
		$this->output( 'Checking current edits...' );
		$revisionRows = $dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'revision' )
			->where( [ 'rev_actor' => $fromActorId ] )
			->caller( __METHOD__ )
			->fetchRowCount();
		$this->output( "found $revisionRows.\n" );

		$this->output( 'Checking deleted edits...' );
		$archiveRows = $dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'archive' )
			->where( [ 'ar_actor' => $fromActorId ] )
			->caller( __METHOD__ )
			->fetchRowCount();
		$this->output( "found $archiveRows.\n" );

		// Don't count recent changes if we're not supposed to
		$recentChangesRows = 0;
		if ( !$this->hasOption( 'norc' ) ) {
			$this->output( 'Checking recent changes...' );
			$recentChangesRows = $dbw->newSelectQueryBuilder()
				->select( ISQLPlatform::ALL_ROWS )
				->from( 'recentchanges' )
				->where( [ 'rc_actor' => $fromActorId ] )
				->caller( __METHOD__ )
				->fetchRowCount();
			$this->output( "found $recentChangesRows.\n" );
		}

		$total = $revisionRows + $archiveRows + $recentChangesRows;
		$this->output( "\nTotal entries to change: $total\n" );

		$toActorId = $actorNormalization->acquireActorId( $importUser, $dbw );
		if ( !$this->hasOption( 'no-run' ) && $total ) {
			$this->output( "\n" );
			if ( $revisionRows ) {
				// Assign edits
				$this->output( 'Assigning current edits...' );
				$dbw->newUpdateQueryBuilder()
					->update( 'revision' )
					->set( [ 'rev_actor' => $toActorId ] )
					->where( [ 'rev_actor' => $fromActorId ] )
					->caller( __METHOD__ )
					->execute();
				$this->output( "done.\n" );
			}

			if ( $archiveRows ) {
				$this->output( 'Assigning deleted edits...' );
				$dbw->newUpdateQueryBuilder()
					->update( 'archive' )
					->set( [ 'ar_actor' => $toActorId ] )
					->where( [ 'ar_actor' => $fromActorId ] )
					->caller( __METHOD__ )
					->execute();
				$this->output( "done.\n" );
			}

			// Update recent changes if required
			if ( $recentChangesRows ) {
				$this->output( 'Updating recent changes...' );
				$dbw->newUpdateQueryBuilder()
					->update( 'recentchanges' )
					->set( [ 'rc_actor' => $toActorId ] )
					->where( [ 'rc_actor' => $fromActorId ] )
					->caller( __METHOD__ )
					->execute();
				$this->output( "done.\n" );
			}
		}

		$this->commitTransactionRound( __METHOD__ );
		return $total;
	}
}

// @codeCoverageIgnoreStart
return AssignImportedEdits::class;
// @codeCoverageIgnoreEnd
