<?php

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class GenerateExtensionDatabaseList extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->mDescription = "Generates lists of all wikis with given extension(s)";
		$this->addOption( 'extensions', 'Extension(s) to generate list for. Multiple extensions should separated by pipe (|)', true, true );
	}

	public function execute() {
		$lists = [];

		$extArray = explode( '|', $this->getOption( 'extensions' ) );

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		$dbr = wfGetDB( DB_REPLICA, [], $config->get( 'CreateWikiDatabase' ) );

		foreach( $extArray as $ext ) {
			$mwSettings = $dbr->select(
				'mw_settings',
				's_dbname',
				[
					 's_extensions' . $dbr->buildLike( $dbr->anyString(), "$ext", $dbr->anyString() )
				],
				__METHOD__,
			);

			foreach ( $mwSettings as $wiki ) {
 				$lists[$ext][$wiki->s_dbname] = [];
			}
		}

		$shellUser = posix_getpwuid( posix_geteuid() )['name'];
		foreach ( $extArray as $ext ) {
			file_put_contents( "/home/{$shellUser}/{$ext}.json", json_encode( [ 'combi' => $lists[$ext] ] ), LOCK_EX );
		}
	}
}

$maintClass = 'GenerateExtensionDatabaseList';
require_once RUN_MAINTENANCE_IF_MAIN;
