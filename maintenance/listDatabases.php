<?php
require_once( "$IP/maintenance/commandLine.inc" );
foreach ( $wgLocalDatabases as $db ) {
	print "$db\n";
}
