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

		$this->addOption( 'directory', 'Directory to store the list files in.', true, true );
		$this->addOption( 'extension', $desc, true, true, false, true );
	}

	public function execute() {
		$extArray = $this->getOption( 'extension' );
		$directory = $this->getOption( 'directory' );

		$usePhp = $this->getConfig()->get( 'CreateWikiUsePhp' );
		$dbr = $this->getDB( DB_REPLICA, [], $this->getConfig()->get( 'CreateWikiUsePhpCache' ) );

		foreach ( $extArray as $ext ) {
			$list = [];

			$mwSettings = $dbr->select(
				'mw_settings',
				[
					's_dbname',
					's_extensions',
				],
				[
					 's_extensions' . $dbr->buildLike( $dbr->anyString(), $ext, $dbr->anyString() )
				],
				__METHOD__,
			);

			foreach ( $mwSettings as $row ) {
				if ( in_array( $ext, json_decode( $row->s_extensions, true ) ?? [] ) ) {
					$list[$row->s_dbname] = [];
				}
			}

			if ( $list ) {
				$filePath = "{$directory}/{$ext}" . ( $usePhp ? '.php' : '.json' );

				if ( $usePhp ) {
					// Output as a PHP file with array syntax
					$fileContent = "<?php\n\nreturn " . var_export( [ 'databases' => $list ], true ) . ";\n";
				} else {
					// Output as JSON
					$fileContent = json_encode( [ 'combi' => $list ], JSON_PRETTY_PRINT );
				}

				file_put_contents( $filePath, $fileContent, LOCK_EX );
			} else {
				$this->output( "No wikis are using {$ext} so a database list was not generated for it.\n" );
			}
		}
	}
}

$maintClass = GenerateExtensionDatabaseList::class;
require_once RUN_MAINTENANCE_IF_MAIN;
