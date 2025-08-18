<?php

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\DBQueryError;
use function getenv;
use function putenv;
use const MW_INSTALL_PATH;

$wgGlobalUsageDatabase = 'wikidb';
$wgHooks['MediaWikiServices'][] = 'wfOnMediaWikiServices';

function wfOnMediaWikiServices( MediaWikiServices $services ): void {
	try {
		if ( getenv( 'ECHO_SQL_EXECUTED' ) ) {
			return;
		}

		$db = wfInitDBConnection( $services );
		$db->selectDomain( 'wikidb' );
		if ( !$db->tableExists( 'echo_unread_wikis', __METHOD__ ) ) {
			$db->sourceFile( MW_INSTALL_PATH . '/extensions/Echo/sql/mysql/tables-sharedtracking-generated.sql' );
		}

		putenv( 'ECHO_SQL_EXECUTED=true' );
	} catch ( DBQueryError ) {
		// Do nothing
	}
}

function wfInitDBConnection( MediaWikiServices $services ): Database {
	return $services->getDatabaseFactory()->create( 'mysql', [
		'host' => $services->getMainConfig()->get( MainConfigNames::DBserver ),
		'user' => 'root',
	] );
}
