<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

// Script from https://github.com/wikimedia/mediawiki-extensions-WikimediaMaintenance/blob/master/filebackend/setZoneAccess.php
// but modified for miraheze needs.
// Licensed under the same license as https://github.com/wikimedia/mediawiki-extensions-WikimediaMaintenance/blob/master/filebackend/setZoneAccess.php
class SetZoneAccess extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addOption( 'backend', 'Name of the file backend' );
		$this->addOption( 'private', 'Make all containers private' );
	}

	public function execute() {
		$swift_backend = $this->hasOption( 'backend' ) ?
			$this->getOption( 'backend' ) : 'miraheze-swift';
		$backend = FileBackendGroup::singleton()->get( $swift_backend );

        // container will be <wikiID>-mw
		$dir = $backend->getContainerStoragePath( 'mw' );
		$secure = $this->hasOption( 'private' )
			? [ 'noAccess' => true, 'noListing' => true ]
			: [];
		$this->prepareDirectory( $backend, $dir, $secure );
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
$maintClass = 'SetZoneAccess';
require_once RUN_MAINTENANCE_IF_MAIN;
