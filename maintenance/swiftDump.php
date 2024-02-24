<?php

namespace Miraheze\MirahezeMagic\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;

class SwiftDump extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addOption( 'filename', 'Filename of the .tar.gz dump', true, true );
	}

	public function execute() {
		global $wmgSwiftPassword;

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		$wiki = $this->getConfig()->get( MainConfigNames::DBname );
		$this->output( "Starting swift dump for $wiki...\n" );

		// Available disk space must be 10GB
		$df = disk_free_space( '/tmp' );
		if ( $df < 10 * 1024 * 1024 * 1024 ) {
			$this->fatalError( "Not enough disk space available ( < 10GB). Aborting dump.\n" );
		}
		// If no wiki then errror
		if ( !$wiki ) {
			$this->fatalError( 'No wiki has been defined' );
		}

		// Download the Swift container
		Shell::command(
			'swift', 'download',
			"miraheze-$wiki-local-public",
			'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
			'-U', 'mw:media',
			'-K', $wmgSwiftPassword,
			'-D', "/tmp/$wiki",
			'--object-threads', '1',
		)->limits( $limits )
			->disableSandbox()
			->execute();

		// Compress Swift container (.tar.gz)
		Shell::command(
			'tar', '-czf',
			'/tmp/' . $this->getOption( 'filename' ),
			"/tmp/$wiki",
			'--remove-files'
		)->limits( $limits )
			->disableSandbox()
			->execute();

		$this->output( "Swift dump for $wiki complete!\n" );
	}
}

$maintClass = SwiftDump::class;
require_once RUN_MAINTENANCE_IF_MAIN;
