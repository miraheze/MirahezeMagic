<?php
require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\Shell\Shell;

class SwiftDump extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'filename', 'Filename of the .tar.gz dump', true, true );
	}

	public function execute() {
		global $wmgSwiftPassword;

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		$wiki = $this->getConfig()->get( 'DBname' );
		$this->output( "Starting swift dump for $wiki...\n" );

		// Available disk space must be 10GB
		$df = disk_free_space( '/tmp' );
		if ( $df < 10 * 1024 * 1024 * 1024 ) {
			$this->error( "Not enough disk space available ( < 10GB). Aborting dump.\n" );
			return;
		}
		// If no wiki then errror
		if ( !$wiki ) {
			$this->error( "No wiki has been defined" );
			return;
		}

		// Download the Swift container
		Shell::command(
			'swift', 'download',
			"miraheze-$wiki-local-public",
			'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
			'-U', 'mw:media',
			'-K', $wmgSwiftPassword,
			'-D', "/tmp/$wiki",
			'--object-threads', 1,
		)->limits( $limits )
			->restrict( Shell::RESTRICT_NONE )
			->execute();

		// Compress Swift container (.tar.gz)
		Shell::command(
			'tar', '-czf',
			'/tmp/' . $this->getOption( 'filename' ),
			"/tmp/$wiki",
			'--remove-files'
		)->limits( $limits )
			->restrict( Shell::RESTRICT_NONE )
			->execute();

		$this->output( "Swift dump for $wiki complete!\n" );
	}
}

$maintClass = SwiftDump::class;
require_once RUN_MAINTENANCE_IF_MAIN;
