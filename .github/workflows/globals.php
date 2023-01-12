<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBQueryError;

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWikiServices $services ) {
	try {
		global $IP;
		$dbw = wfInitDBConnection();

		if ( !$dbw->tableExists( 'echo_unread_wikis' ) ) {
			$dbw->sourceFile( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" );
		}
	} catch ( DBQueryError $e ) {
		return;
	}
}
