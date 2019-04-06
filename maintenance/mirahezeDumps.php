<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class mirahezeDumps extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addOption( 'file-name', 'The name of the file to dump to.', false, false );
		$this->addOption( 'use-gz', 'Use gzip to compress file', false, false );
	}

	public function execute() {
		$fileName = wfEscapeShellArg( $this->getOption( 'file-name' ) );

		exec(
			"/usr/bin/php /srv/mediawiki/w/maintenance/dumpBackup.php " . $this->getOption( 'wiki' ) .
			" --full > ${fileName}.gz"
		);

		exec( "gzip -c ${$fileName}.gz > ${fileName}" );
	}
}

$maintClass = 'mirahezeDumps';
require_once RUN_MAINTENANCE_IF_MAIN;
