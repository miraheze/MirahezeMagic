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
* @version 1.1
*/

require_once( "/srv/mediawiki/w/maintenance/Maintenance.php" );

class FindInactiveWikis extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'warn', 'Actually warn wikis which are considered inactive but not closable yet', false, false );
		$this->addOption( 'close', 'Actually close wikis which are considered inactive and closable.', false, false );
		$this->mDescription = 'A script to find inactive wikis in a farm.';
	}

	public function execute() {
		global $wgFindInactiveWikisWhitelist;
		$dbr = wfGetDB( DB_SLAVE );
		$dbr->selectDB( 'metawiki' ); // force this

		$res = $dbr->select(
			'cw_wikis',
			'wiki_dbname',
			array(),
			__METHOD__
		);

		foreach ( $res as $row ) {
			$dbname = $row->wiki_dbname;
	
			if ( in_array( $dbname, $wgFindInactiveWikisWhitelist ) ) {
				continue; // Wiki is in whitelist, do not check.
			}
	
			// Apparently I need to force this here too, so I'll do that.
			$dbr->selectDB( 'metawiki' );
	
			$res = $dbr->selectRow(
				'logging',
				'log_timestamp',
				array(
					'log_action' => 'createwiki',
					'log_params' => serialize( array( '4::wiki' => $dbname ) )
				),
				__METHOD__,
				array( // Sometimes a wiki might have been created multiple times.
					'ORDER BY' => 'log_timestamp DESC'
				)
			);
	
			if ( !isset( $res ) || !isset( $res->log_timestamp ) ) {
				$this->output( "ERROR: couldn't determine when {$dbname} was created!\n" );
				continue;
			}
	
			if ( $res && $res->log_timestamp < date( "YmdHis", strtotime( "-45 days" ) ) ) {
				$this->checkLastActivity( $dbname );
			}
		}
	}

	public function checkLastActivity( $wiki ) {
		$dbr = wfGetDB( DB_SLAVE );
		$dbr->selectDB( $wiki );

		// Exclude our sitenotice from edits so that we don't get 60 days after 45
		$title = Title::newFromText( 'MediaWiki:Sitenotice' );

		$res = $dbr->selectRow(
			'recentchanges',
			'rc_timestamp',
			array(
				"NOT (rc_namespace = " . $title->getNamespace .
				" AND rc_title = " . $dbr->addQuotes( $title->getDBkey ) .
				" AND rc_comment = 'Inactivity warning')"
			),
			__METHOD__,
			array(
				'ORDER BY' => 'rc_timestamp DESC'
			)
		);

		// Wiki doesn't seem inactive: go on to the next wiki.
		if ( isset( $res->rc_timestamp ) && $res->rc_timestamp > date( "YmdHis", strtotime( "-45 days" ) ) ) {
			return true;
		}

		if ( isset( $res->rc_timestamp ) && $res->rc_timestamp < date( "YmdHis", strtotime( "-60 days" ) ) ) {
			if ( $this->hasOption( 'close' ) ) {
				$this->closeWiki( $wiki );
				$this->output( "Wiki {$wiki} was eligible for closing and it was.\n" );
			} else {
				$this->output( "It looks like {$wiki} should be closed. Timestamp of last recent changes entry: {$res->rc_timestamp}\n" );
			}
		} elseif ( isset( $res->rc_timestamp ) && $res->rc_timestamp < date( "YmdHis", strtotime( "-45 days" ) ) ) {
			if ( $this->hasOption( 'warn' ) ) {
				$this->warnWiki( $wiki );
				$this->output( "Wiki {$wiki} was eligible for a warning notice and one was given.\n" );
			} else {
				$this->output( "It looks like {$wiki} should get a warning notice. Timestamp of last recent changes entry: {$res->rc_timestamp}\n" );
			}
		} else {
			if ( $this->hasOption( 'warn' ) ) {
				$this->warnWiki( $wiki );
				$this->output( "No recent changes entries have been found for {$wiki}. Therefore marking as inactive.\n" );
			} else {
				$this->output( "No recent changes have been found for {$wiki}.\n" );
			}
		}

		return true;
	}

	public function closeWiki( $wiki ) {
		$dbw = wfGetDB( DB_SLAVE );
		$dbw->selectDB( 'metawiki' ); // force this

		$dbw->query( 'UPDATE cw_wikis SET wiki_closed=1 WHERE wiki_dbname=' . $dbw->addQuotes( $wiki ) . ';' );

		$dbw->selectDB( $wiki );
		
		// Empty MediaWiki:Sitenotice
		$title = Title::newFromText( 'MediaWiki:Sitenotice' );
		$article = WikiPage::factory( $title );
		$content = $article->getContent( Revision::RAW );
		$text = ContentHandler::getContentText( $content );

		if ( $text != '' ) {
			$article->doEditContent(
				new WikitextContent(
					''
				), // Text
				'Remove inactivity notice', // Edit summary
				0,
				false,
				User::newFromName( 'MediaWiki default' ) // We don't want to have incorrect user_id - user_name entries
			);
		}
		return true;
	}

	public function warnWiki( $wiki ) {
		$dbr = wfGetDB( DB_SLAVE );
		$dbr->selectDB( $wiki );

		// Handle MediaWiki:Sitenotice
		$title = Title::newFromText( 'MediaWiki:Sitenotice' );
		$article = WikiPage::factory( $title );
		$content = $article->getContent( Revision::RAW );
		$text = ContentHandler::getContentText( $content );

		// Get content of 'miraheze-warnmessage'
		$wmtext = wfMessage( 'miraheze-warnmessage' )->plain();

		// Only write the inactvity warning if the wiki hasn't been warned yet
		if ( $text != $wmtext ) {
			$article->doEditContent(
				new WikitextContent(
					wfMessage( 'miraheze-warnmessage' )->plain() 
				), // Text
				'Inactivity warning', // Edit summary
				0,
				false,
				User::newFromName( 'MediaWiki default' ) // We don't want to have incorrect user_id - user_name entries
			);
		}
		return true;
	}
}

$maintClass = 'FindInactiveWikis';
require_once RUN_MAINTENANCE_IF_MAIN;
