<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBQueryError;

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWikiServices $services ) {
	try {
		if ( getenv( 'ECHO_SQL_EXECUTED' ) ) {
			return;
		}

		$db = wfInitDBConnection();

		$db->selectDomain( 'wikidb' );
		if ( !$db->tableExists( 'echo_unread_wikis' ) ) {
			$db->sourceFile( MW_INSTALL_PATH . '/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql' );
		}

		putenv( 'ECHO_SQL_EXECUTED=true' );
	} catch ( DBQueryError $e ) {
		return;
	}
}
