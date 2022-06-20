<?php

$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWiki\MediaWikiServices $services ) {
	try {
		global $IP;
		$dbw = $services->getDBLoadBalancerFactory()
			->getMainLB( 'wikidb' )->getMaintenanceConnectionRef( DB_PRIMARY, [], 'wikidb' );

		if ( !$dbw->tableExists( 'echo_unread_wikis' ) ) {
			$dbw->sourceFile( "$IP/extensions/Echo/db_patches/echo_unread_wikis.sql" );
		}
	} catch ( Wikimedia\Rdbms\DBQueryError $e ) {
		return;
	}
}
