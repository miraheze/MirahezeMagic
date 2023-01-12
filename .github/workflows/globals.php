<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBConnectionError;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\DBUnexpectedError;

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWikiServices $services ) {
	try {
		$dbw = $services->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		if ( !$dbw->tableExists( 'echo_unread_wikis' ) ) {
			$dbw->sourceFile( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" );
		}
	} catch ( DBConnectionError | DBQueryError | DBUnexpectedError $e ) {
		return;
	}
}
