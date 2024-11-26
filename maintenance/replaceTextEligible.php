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
		$this->output( sprintf( 'Got %d pages from the page table and %d deleted pages from the archive table to process, hang tight...', $pages->numRows(), $deletedPageIDs->numRows() ) );

		// Arrays to hold the names of pages preventing ReplaceText from working correctly
		$problematicPages = [];
		$problematicDeletedPages = [];

		// Regular pages
		$this->output( 'Processing regular pages' );
		foreach ( $pages as $page ) {
			$isGzipped = $dbr->newSelectQueryBuilder()
				->select( '1' )
				->from( 'slots' )
				->join( 'content', null, 'content_id = slot_content_id' )
				->join( 'text', null, $dbr->expr(
					'old_id',
					'=',
					$dbr->buildIntegerCast( $dbr->buildSubString( 'content_address', 4 ) ),
				) )
				->where( [
					'slot_revision_id' => $page->page_latest,
					$dbr->expr(
						'old_flags',
						IExpression::LIKE,
						$dbr->buildLike( $dbr->anyString(), 'gzip', $dbr->anyString() ),
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
		$this->output( 'Processing deleted pages' );
		foreach ( $deletedPageIDs as $deletedPageID ) {
			$deletedPage = $dbr->newSelectQueryBuilder()
				->select( [ 'ar_namespace', 'ar_title' ] )
				->from( 'archive' )
				->join( 'slots', null, 'slot_revision_id = ar_rev_id' )
				->join( 'content', null, 'content_id = slot_content_id' )
				->join( 'text', null, $dbr->expr(
					'old_id',
					'=',
					$dbr->buildIntegerCast( $dbr->buildSubString( 'content_address', 4 ) ),
				) )
				->where( [
					'ar_page_id' => $deletedPageID->ar_page_id,
					$dbr->expr(
						'old_flags',
						IExpression::LIKE,
						$dbr->buildLike( $dbr->anyString(), 'gzip', $dbr->anyString() ),
					),
				] )
				->orderBy( 'ar_rev_id', SelectQueryBuilder::SORT_DESC )
				->limit( 1 )
				->caller( __METHOD__ )
				->fetchRow();

			if ( $deletedPage ) {
				// The latest revision of this page is compressed
				$problematicPages[] = $titleFormatter->formatTitle( $deletedPage->ar_namespace, $deletedPage->ar_title );
			}
		}
		if ( count( $problematicPages ) > 0 || count( $problematicDeletedPages ) > 0 ) {
			$this->output( 'ReplaceText should not be enabled on this wiki.' );
			if ( count( $problematicPages ) > 0 ) {
				$this->output( 'The following pages\' latest revisions are compressed:' );
				$this->output( implode( ', ', $problematicPages ) );
			}
			if ( count( $problematicDeletedPages ) > 0 ) {
				$this->output( 'The following deleted pages\' latest revisions are compressed:' );
				$this->output( implode( ', ', $problematicDeletedPages ) );
				$this->output( 'If these pages are undeleted with ReplaceText enabled, usage of the extension will cause problems.' );
			}
		} else {
			$this->output( 'There\'s no problem with this wiki\'s pages; enabling ReplaceText in this wiki is safe.' );
		}
	}
}

$maintClass = ReplaceTextEligible::class;
require_once RUN_MAINTENANCE_IF_MAIN;
