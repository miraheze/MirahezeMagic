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

		$wiki = $this->getConfig()->get( MainConfigNames::DBname );

		// If no wiki then error
		if ( !$wiki ) {
			$this->fatalError( 'No wiki has been defined.' );
		}

		$this->output( "Starting swift dump for $wiki...\n" );

		$container = "miraheze-$wiki-local-public";
		$limits = [
			'memory' => 0,
			'filesize' => 0,
			'time' => 0,
			'walltime' => 0,
		];

		// Calculate the required disk space (container size + 5GB)
		$containerSize = $this->getContainerSize( $container, $limits );

		// 5GB in bytes
		$additionalSpace = 5 * 1024 * 1024 * 1024;
		$requiredSpace = $containerSize + $additionalSpace;
		$availableSpace = (int)disk_free_space( '/tmp' );

		if ( $availableSpace < $requiredSpace ) {
			$contentLanguage = $this->getServiceContainer()->getContentLanguage();
			$formattedRequiredSpace = $contentLanguage->formatSize( $requiredSpace );
			$formattedAvailableSpace = $contentLanguage->formatSize( $availableSpace );

			// We use exit code 75 to allow for custom handling
			$this->fatalError( sprintf(
				"Not enough disk space available (required: %s, available: %s). Aborting dump...\n",
				$formattedRequiredSpace,
				$formattedAvailableSpace
			), 75 );
		}

		// Download the Swift container
		Shell::command(
			'swift', 'download', $container,
			'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
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

	private function getContainerSize(
		string $container,
		array $limits
	): int {
		global $wmgSwiftPassword;

		$output = Shell::command(
			'swift', 'stat', $container,
			'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
			'-U', 'mw:media',
			'-K', $wmgSwiftPassword
		)->limits( $limits )
			->disableSandbox()
			->execute()->getStdout();

		if ( preg_match( '/Bytes: (\d+)/', $output, $matches ) ) {
			return (int)$matches[1];
		}
	}
}

$maintClass = SwiftDump::class;
require_once RUN_MAINTENANCE_IF_MAIN;
