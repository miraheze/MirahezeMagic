<?php

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWiki\MediaWikiServices $services ) {
	try {
		global $IP;

		static $dbr = null;
		static $dbw = null;

		$dbr ??= $services->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );

		if ( !$dbr->tableExists( 'echo_unread_wikis' ) ) {
			$dbw ??= $services->getDBLoadBalancer()
				->getMaintenanceConnectionRef( DB_PRIMARY );

			// < MediaWiki 1.39 — Remove once CI drops MediaWiki 1.38 support
			if ( file_exists( "$IP/extensions/Echo/db_patches/echo_unread_wikis.sql" ) ) {
				$dbw->startAtomic();
				$dbw->sourceFile( "$IP/extensions/Echo/db_patches/echo_unread_wikis.sql" );
				$dbw->endAtomic();
				return;
			}

			// MediaWiki 1.39+
			if ( file_exists( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" ) ) {
				$dbw->startAtomic();
				$dbw->sourceFile( "$IP/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql" );
				$dbw->endAtomic();
			}
		}
	} catch ( Wikimedia\Rdbms\DBQueryError $e ) {
		return;
	}
}
