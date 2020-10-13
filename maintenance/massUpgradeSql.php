<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class massUpgradeSql extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Mass runs sql using file/or stdin' );
		$this->addArg( 'listfile', 'File with path to schema ' .
			'If not given, stdin will be used.', false );
	}

	public function execute() {
		global $wgDBname;

		if ( $this->hasArg( 0 ) ) {
			$file = fopen( $this->getArg( 0 ), 'r' );
		} else {
			$file = $this->getStdin();
		}

		if ( !$file ) {
			$this->fatalError( "Unable to read file, exiting" );
		}

		for ( $linenum = 1; !feof( $file ); $linenum++ ) {
			$line = trim( fgets( $file ) );
			if ( $line == '' ) {
				continue;
			}
			
			exec( "/usr/bin/php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname $line" );
		}
	}
}

$maintClass = 'massUpgradeSql';
require_once RUN_MAINTENANCE_IF_MAIN;
