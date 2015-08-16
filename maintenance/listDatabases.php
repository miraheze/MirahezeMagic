<?php
require_once( '/srv/mediawiki/w/maintenance/commandLine.inc' );
foreach ( $wgLocalDatabases as $db ) {
	print "$db\n";
}
