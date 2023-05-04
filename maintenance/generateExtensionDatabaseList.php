<?php

namespace Miraheze\MirahezeMagic\Maintenance;

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class GenerateExtensionDatabaseList extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generate database list(s) of all wikis with given extension or skin enabled within ManageWiki.' );

		$desc = 'Extension or skin to generate database list for. ' .
			'This option may be passed multiple times to generate multiple database lists at once.';

		$this->addOption( 'extension', $desc, true, true, false, true );
	}

	public function execute() {
		$lists = [];

		$extArray = $this->getOption( 'extension' );

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		$dbr = $this->getDB( DB_REPLICA, [], $config->get( 'CreateWikiDatabase' ) );

		foreach ( $extArray as $ext ) {
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
