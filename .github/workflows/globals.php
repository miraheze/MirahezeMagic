<?php

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWiki\MediaWikiServices $services ) {
	try {
		global $IP;

		static $dbw = null;
		$dbw ??= $services->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		if ( !$dbw->tableExists( 'echo_unread_wikis' ) ) {
			// < MediaWiki 1.39 — Remove once CI drops MediaWiki 1.38 support
			if ( file_exists( "$IP/extensions/Echo/db_patches/echo_unread_wikis.sql" ) ) {
				$dbw->sourceFile( "$IP/extensions/Echo/db_patches/echo_unread_wikis.sql" );
				return;
			}

			// MediaWiki 1.39+
			if ( file_exists( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" ) ) {
				$dbw->sourceFile( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" );
			}
		}
	} catch ( Wikimedia\Rdbms\DBQueryError $e ) {
		return;
	}
}
