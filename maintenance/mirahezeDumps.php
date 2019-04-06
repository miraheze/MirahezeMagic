<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class mirahezeDumps extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addOption( 'file-name', 'The name of the file to dump to.', false, true );
	}

        public function execute() {
		global $wgDBname;

		$fileName = wfEscapeShellArg( $this->getOption( 'file-name' ) );

		exec(
			"/usr/bin/php /srv/mediawiki/w/maintenance/dumpBackup.php --wiki ${wgDBname} --full > " . $fileName . ".gz"
		);

		exec( "gzip -c ${fileName}.gz > ${fileName}" );
	}
}

$maintClass = 'mirahezeDumps';
require_once RUN_MAINTENANCE_IF_MAIN;
