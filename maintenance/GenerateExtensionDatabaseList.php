<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\StaticArrayWriter;

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

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbr = $connectionProvider->getReplicaDatabase( 'virtual-createwiki' );

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
				$contents = StaticArrayWriter::write( [ 'databases' => $list ], 'Automatically generated' );
				file_put_contents( "$directory/$ext.php", $contents, LOCK_EX );
			} else {
				$this->output( "No wikis are using $ext so a database list was not generated for it.\n" );
			}
		}
	}
}

// @codeCoverageIgnoreStart
return GenerateExtensionDatabaseList::class;
// @codeCoverageIgnoreEnd
