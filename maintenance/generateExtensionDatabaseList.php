<?php

namespace Miraheze\MirahezeMagic\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;

class GenerateExtensionDatabaseList extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generate database list(s) of all wikis with given extension or skin enabled within ManageWiki.' );

		$desc = 'Extension or skin to generate database list for. ' .
			'This option may be passed multiple times to generate multiple database lists at once.';

		$this->addOption( 'directory', 'Directory to store the json file in.', true, true );
		$this->addOption( 'extension', $desc, true, true, false, true );
	}

	public function execute() {
		$lists = [];

		$extArray = $this->getOption( 'extension' );

		$dbr = $this->getDB( DB_REPLICA, [], $this->getConfig()->get( 'CreateWikiDatabase' ) );

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

		$directory = $this->getOption( 'directory' );
		foreach ( $extArray as $ext ) {
			file_put_contents( "{$directory}/{$ext}.json", json_encode( [ 'combi' => $lists[$ext] ] ), LOCK_EX );
		}
	}
}

$maintClass = GenerateExtensionDatabaseList::class;
require_once RUN_MAINTENANCE_IF_MAIN;
