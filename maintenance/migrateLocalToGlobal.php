<?php
// LICENSE: GNU GENERAL PUBLIC LICENSE V2
// https://github.com/wikimedia/mediawiki-extensions-CentralAuth/blob/aecf26230e1ddc29ab80f8a539c450a7d4e64905/COPYING

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class MigrateLocalToGlobal extends Maintenance {

	/** @var float */
	protected $start;

	/** @var int */
	protected $migrated;

	/** @var int */
	protected $total;

	/** @var string */
	protected $fromPrefix;

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CentralAuth' );
		$this->addDescription( 'Migrates local users to global users where possible' );
		$this->addOption( 'username', 'If specified, only migrate this username', false, true );
		$this->start = microtime( true );
		$this->migrated = 0;
		$this->total = 0;
		$this->fromPrefix = '';
	}

	public function execute() {
		$this->output( "CentralAuth migration pass 1:\n" );
		$this->output( "Finding accounts which can be migrated without interaction...\n" );

		$dbBackground = CentralAuthUtils::getCentralReplicaDB();
		$gnName = [];

		$user = $this->getOption( 'username', false );
		if ( $user ) {
			$gnName = [ 'gn_name' => $user ];
		}

		$result = $dbBackground->select(
			'globalnames',
			[ 'gn_name' ],
			$gnName,
			__METHOD__ );
		foreach ( $result as $row ) {
			$this->fromPrefix = $row->gn_name;
			$central = new CentralAuthUser( $row->gn_name, CentralAuthUser::READ_LATEST );
			if ( $central->storeAndMigrate() ) {
				$this->migrated++;
			}
			if ( ++$this->total % 1000 == 0 ) {
				$this->migratePassOneReport();
			}
		}
		$this->migratePassOneReport();
		$this->output( "done.\n" );
	}

	private function migratePassOneReport() {
		$delta = microtime( true ) - $this->start;
		$this->output( sprintf(
			"%s processed %d usernames (%.1f/sec), %d (%.1f%%) fully migrated (@ %s)\n",
			wfTimestamp( TS_DB ),
			$this->total,
			$this->total / $delta,
			$this->migrated,
			$this->migrated / $this->total * 100.0,
			$this->fromPrefix
		) );
	}
}

$maintClass = MigrateLocalToGlobal::class;
require_once RUN_MAINTENANCE_IF_MAIN;
