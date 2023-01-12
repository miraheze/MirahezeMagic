<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\DBUnexpectedError;

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWikiServices $services ) {
	try {
		global $IP;

		static $dbw = null;
		$dbw ??= $services->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		if ( !$dbw->tableExists( 'echo_unread_wikis' ) ) {
			$dbw->sourceFile( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" );
		}
	} catch ( DBQueryError | DBUnexpectedError $e ) {
		return;
	}
}
