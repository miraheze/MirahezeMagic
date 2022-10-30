<?php
require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class SetZoneAccess extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'backend', 'Name of the file backend', true, true );
		$this->addOption( 'private', 'Make all containers private' );
	}

	public function execute() {
		$backend = MediaWikiServices::getInstance()->getFileBackendGroup()
			->get( $this->getOption( 'backend' ) );
		foreach ( [ 'public', 'thumb', 'transcoded', 'temp', 'deleted' ] as $zone ) {
			$dir = $backend->getContainerStoragePath( "local-$zone" );
			$secure = ( $zone === 'deleted' || $zone === 'temp' || $this->hasOption( 'private' ) )
				? [ 'noAccess' => true, 'noListing' => true ]
				: [];
			$this->prepareDirectory( $backend, $dir, $secure );
		}
		foreach ( [ 'timeline-render' ] as $container ) {
			$dir = $backend->getContainerStoragePath( $container );
			$secure = $this->hasOption( 'private' )
				? [ 'noAccess' => true, 'noListing' => true ]
				: [];
			$this->prepareDirectory( $backend, $dir, $secure );
		}
	}

	protected function prepareDirectory( FileBackend $backend, $dir, array $secure ) {
		// Create zone if it doesn't exist...
		$this->output( "Making sure $dir exists..." );
		$status = $backend->prepare( [ 'dir' => $dir ] + $secure );
		// Make sure zone has the right ACLs...
		if ( count( $secure ) ) { // private
			$this->output( "making '$dir' private..." );
			$status->merge( $backend->secure( [ 'dir' => $dir ] + $secure ) );
		} else { // public
			$this->output( "making '$dir' public..." );
			$status->merge( $backend->publish( [ 'dir' => $dir, 'access' => true ] ) );
		}
		$this->output( "done.\n" );
		if ( !$status->isOK() ) {
			print_r( $status->getErrors() );
		}
	}
}
$maintClass = SetZoneAccess::class;
require_once RUN_MAINTENANCE_IF_MAIN;
