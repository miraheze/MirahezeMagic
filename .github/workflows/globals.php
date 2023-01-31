<?php

use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\DBQueryError;

$wgObjectCaches['memcached'] = [
	'class'                => MemcachedPeclBagOStuff::class,
	'serializer'           => 'php',
	'persistent'           => false,
	'servers'              => [ '127.0.0.1:11211' ],
	// Effectively disable the failure limit (0 is invalid)
	'server_failure_limit' => 1e9,
	// Effectively disable the retry timeout
	'retry_timeout'        => -1,
	'loggroup'             => 'memcached',
	// 500ms, in microseconds
	'timeout'              => 1 * 1e6,
];

$wgMainCacheType = 'memcached';

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
