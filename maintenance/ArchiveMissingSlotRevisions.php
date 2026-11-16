<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
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
 */

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentityValue;
use RuntimeException;
use Throwable;
use Wikimedia\Rdbms\IMaintainableDatabase;
use const MW_VERSION;

class ArchiveMissingSlotRevisions extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Find and archive revisions without slots on the specified page.' );
		$this->addOption( 'page', 'Page title to check for revisions without slots.', true, true );
		$this->addOption( 'dry-run', 'Report changes without writing them.' );
	}

	public function execute(): void {
		$title = Title::newFromText( $this->getOption( 'page' ) );
		if ( !$title || $title->isExternal() || $title->getNamespace() < 0 ) {
			$this->fatalError( 'Invalid page title.' );
		}
		$dryRun = $this->hasOption( 'dry-run' );
		$dbw = $this->getDB( DB_PRIMARY );

		$this->beginTransactionRound( __METHOD__ );
		try {
			$query = $dbw->newSelectQueryBuilder()
				->select( [ 'page_id', 'page_namespace', 'page_title', 'page_latest' ] )
				->from( 'page' )
				->where( [
					'page_namespace' => $title->getNamespace(),
					'page_title' => $title->getDBkey(),
				] )
				->options( $dryRun ? [] : [ 'FOR UPDATE' ] );
			$page = $query->caller( __METHOD__ )->fetchRow();
			if ( !$page ) {
				throw new RuntimeException( 'Page does not exist.' );
			}

			$query = $dbw->newSelectQueryBuilder()
				->select( [ 'revision.*', 'actor_user', 'actor_name' ] )
				->from( 'revision' )
				->leftJoin( 'actor', null, 'actor_id = rev_actor' )
				->leftJoin( 'slots', null, 'slot_revision_id = rev_id' )
				->where( [ 'rev_page' => $page->page_id, 'slot_revision_id' => null ] )
				->options( $dryRun ? [] : [ 'FOR UPDATE' ] );
			$revisions = iterator_to_array( $query->caller( __METHOD__ )->fetchResultSet() );
			if ( !$revisions ) {
				$this->commitTransactionRound( __METHOD__ );
				$this->output( "No revisions without slots found.\n" );
				return;
			}
			$revisionIds = array_column( $revisions, 'rev_id' );
			if ( in_array( $page->page_latest, $revisionIds ) ) {
				throw new RuntimeException( "Revision {$page->page_latest} is the current revision." );
			}
			$this->checkReferences( $dbw, $revisionIds );

			$archiveRows = [];
			$revAuthors = [];
			foreach ( $revisions as $revision ) {
				$this->output( "Archiving revision {$revision->rev_id}" . ( $dryRun ? ' (dry run)' : '' ) . "\n" );
				$archiveRows[] = [
					'ar_namespace' => $page->page_namespace,
					'ar_title' => $page->page_title,
					'ar_comment_id' => $revision->rev_comment_id,
					'ar_actor' => $revision->rev_actor,
					'ar_timestamp' => $revision->rev_timestamp,
					'ar_minor_edit' => $revision->rev_minor_edit,
					'ar_rev_id' => $revision->rev_id,
					'ar_deleted' => $revision->rev_deleted,
					'ar_len' => $revision->rev_len,
					'ar_page_id' => $revision->rev_page,
					'ar_parent_id' => $revision->rev_parent_id,
				];
				if ( $revision->actor_user ) {
					$revAuthors[] = [
						new UserIdentityValue( (int)$revision->actor_user, $revision->actor_name ),
						$revision->rev_timestamp,
					];
				}
			}
			if ( !$dryRun ) {
				$this->getServiceContainer()->getUserEditTracker()->invalidateCachedFirstEditTimestamps(
					version_compare( MW_VERSION, '1.47', '<' ) ? $revAuthors : array_column( $revAuthors, 0 )
				);
				$dbw->newInsertQueryBuilder()
					->insertInto( 'archive' )
					->rows( $archiveRows )
					->caller( __METHOD__ )->execute();
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'revision' )
					->where( [ 'rev_page' => $page->page_id, 'rev_id' => $revisionIds ] )
					->caller( __METHOD__ )->execute();
				if ( $dbw->affectedRows() !== count( $revisionIds ) ) {
					throw new RuntimeException( 'Unexpected number of deleted revisions.' );
				}
				foreach ( [ 'recentchanges' => 'rc_this_oldid', 'ip_changes' => 'ipc_rev_id' ] as $table => $field ) {
					$dbw->newDeleteQueryBuilder()
						->deleteFrom( $table )
						->where( [ $field => $revisionIds ] )
						->caller( __METHOD__ )->execute();
				}
				$dbw->newUpdateQueryBuilder()
					->update( 'page' )
					->set( [ 'page_touched' => $dbw->timestamp() ] )
					->where( [ 'page_id' => $page->page_id ] )
					->caller( __METHOD__ )->execute();
			}
		} catch ( Throwable $e ) {
			$this->rollbackTransactionRound( __METHOD__ );
			$this->fatalError( $e->getMessage() );
		}
		$this->commitTransactionRound( __METHOD__ );
		$this->output( "Done.\n" );
	}

	private function checkReferences( IMaintainableDatabase $dbw, array $revisionIds ): void {
		$references = [ 'archive' => 'ar_rev_id' ];
		if ( $dbw->tableExists( 'revtag', __METHOD__ ) ) {
			$references['revtag'] = 'rt_revision';
		}
		foreach ( $references as $table => $field ) {
			$revisionId = $dbw->newSelectQueryBuilder()
				->select( $field )->from( $table )
				->where( [ $field => $revisionIds ] )
				->caller( __METHOD__ )->fetchField();
			if ( $revisionId !== false ) {
				throw new RuntimeException( "Revision {$revisionId} is referenced by {$table}.{$field}." );
			}
		}
	}
}

// @codeCoverageIgnoreStart
return ArchiveMissingSlotRevisions::class;
// @codeCoverageIgnoreEnd
