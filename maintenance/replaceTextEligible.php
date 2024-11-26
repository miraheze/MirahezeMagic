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
 * @author Alex
 * @version 1.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

class ReplaceTextEligible extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Checks if the current wiki is eligible for enabling ReplaceText\n See https://meta.miraheze.org/wiki/Tech:Noticeboard?oldid=414759#The_state_of_the_ReplaceText_extension' );
	}

	public function execute() {
		$dbr = $this->getDB( DB_REPLICA );
		$titleFormatter = $this->getServiceContainer()->getTitleFormatter();

		$pages = $dbr->newSelectQueryBuilder()
			->select( [ 'page_latest', 'page_namespace', 'page_title' ] )
			->from( 'page' )
			->caller( __METHOD__ )
			->fetchResultSet();
		$deletedPageIDs = $dbr->newSelectQueryBuilder()
			->select( [ 'ar_page_id' ] )
			->from( 'archive' )
			->distinct()
			->caller( __METHOD__ )
			->fetchResultSet();
		$this->output( "Got {$pages->numRows()} pages from the page table and {$deletedPageIDs->numRows()} deleted pages from the archive table to process, hang tight...\n" );

		// Arrays to hold the names of pages preventing ReplaceText from working correctly
		$problematicPages = [];
		$problematicDeletedPages = [];

		// Regular pages
		$this->output( "Processing regular pages...\n" );
		foreach ( $pages as $page ) {
			$isGzipped = $dbr->newSelectQueryBuilder()
				->select( '1' )
				->from( 'slots' )
				->join( 'content', null, 'content_id = slot_content_id' )
				->join( 'text', null, 'old_id = ' . $dbr->buildIntegerCast( $dbr->buildSubString( 'content_address', 4 ) ) )
				// @phan-suppress-next-line PhanPluginMixedKeyNoKey We intentionally mix string and numeric keys since SelectQueryBuilder::where() can handle both at once
				->where( [
					'slot_revision_id' => $page->page_latest,
					$dbr->expr(
						'old_flags',
						IExpression::LIKE,
						new LikeValue( $dbr->anyString(), 'gzip', $dbr->anyString() ),
					),
				] )
				->caller( __METHOD__ )
				->fetchField();

			if ( $isGzipped ) {
				// The latest revision of this page is compressed
				$problematicPages[] = $titleFormatter->formatTitle( $page->page_namespace, $page->page_title );
			}
		}

		// Deleted pages
		// These can be undeleted on-wiki, and if so, they may also cause issues with ReplaceText
		$this->output( "Processing deleted pages...\n" );
		foreach ( $deletedPageIDs as $deletedPageID ) {
			// Fetch the latest slot revision ID (this part must be separated out from the rest of the JOINs,
			// at least with my knowledge of SQL at the time of writing)
			$slotRevisionId = $dbr->newSelectQueryBuilder()
				->select( 'ar_rev_id' )
				->from( 'archive' )
				->where( [
					'ar_page_id' => $deletedPageID->ar_page_id,
				] )
				->orderBy( 'ar_rev_id', SelectQueryBuilder::SORT_DESC )
				->limit( 1 )
				->caller( __METHOD__ )
				->fetchField();

			// No idea if this is possible, but it's midnight and I'm too lazy to think whether or not it is,
			// so I'm adding this precaution anyway.
			if ( $slotRevisionId === null ) {
				continue;
			}

			$deletedPage = $dbr->newSelectQueryBuilder()
				->select( [ 'ar_namespace', 'ar_title' ] )
				->from( 'slots' )
				->join( 'content', null, 'content_id = slot_content_id' )
				->join( 'text', null, 'old_id = ' . $dbr->buildIntegerCast( $dbr->buildSubString( 'content_address', 4 ) ) )
				->join( 'archive', null, 'ar_rev_id = slot_revision_id' )
				// @phan-suppress-next-line PhanPluginMixedKeyNoKey We intentionally mix string and numeric keys since SelectQueryBuilder::where() can handle both at once
				->where( [
					'slot_revision_id' => $slotRevisionId,
					$dbr->expr(
						'old_flags',
						IExpression::LIKE,
						new LikeValue( $dbr->anyString(), 'gzip', $dbr->anyString() ),
					),
				] )
				->caller( __METHOD__ )
				->fetchRow();

			if ( $deletedPage ) {
				// The latest revision of this page is compressed
				$problematicDeletedPages[] = $titleFormatter->formatTitle( $deletedPage->ar_namespace, $deletedPage->ar_title );
			}
		}

		if ( !$problematicPages && !$problematicDeletedPages ) {
			$this->output( "There's no problem with this wiki's pages; enabling ReplaceText in this wiki is safe.\n" );
			return;
		}

		$this->output( "ReplaceText should not be enabled on this wiki\n" );
		if ( count( $problematicPages ) > 0 ) {
			$this->output( "The following pages' latest revisions are compressed:\n" );
			$this->output( implode( ', ', $problematicPages ) );
			$this->output( "\n" );
		}
		if ( count( $problematicDeletedPages ) > 0 ) {
			$this->output( "The following deleted pages' latest revisions are compressed:\n" );
			$this->output( implode( ', ', $problematicDeletedPages ) );
			$this->output( "\nIf these pages are undeleted with ReplaceText enabled, usage of the extension will cause problems.\n" );
		}
	}
}

$maintClass = ReplaceTextEligible::class;
require_once RUN_MAINTENANCE_IF_MAIN;
