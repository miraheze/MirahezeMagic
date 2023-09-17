<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBQueryError;

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWikiServices $services ) {
	try {
		if ( getenv( 'WIKI_ECHO_CREATION_SQL_EXECUTED' ) ) {
			return;
		}

		global $IP;
		$dbw = wfInitDBConnection();
		$dbw->selectDomain( 'wikidb' );

		if ( !$dbw->tableExists( 'echo_unread_wikis' ) ) {
			$dbw->sourceFile( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" );
		}

		putenv( 'WIKI_ECHO_CREATION_SQL_EXECUTED=true' );
	} catch ( DBQueryError $e ) {
		return;
	}
}
