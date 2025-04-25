<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class FixProjectNS extends Maintenance {

	public function execute(): void {
		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		$databaseUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbw = $databaseUtils->getGlobalPrimaryDB();
		$namespaces = $dbw->newSelectQueryBuilder()
			->select( '*' )
			->from( 'mw_namespaces' )
			->where( [ 'ns_dbname' => $dbname ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $namespaces as $ns ) {
			if ( (int)$ns->ns_namespace_id !== NS_PROJECT && (int)$ns->ns_namespace_id !== NS_PROJECT_TALK ) {
				continue;
			}
			$additional = json_decode( $ns->ns_additional, true );
			if ( (int)$ns->ns_namespace_id === NS_PROJECT ) {
				$value = $additional['wgMetaNamespace'];
			} else {
				$value = $additional['wgMetaNamespaceTalk'];
			}

			$this->output( "Setting namespace {$ns->ns_namespace_id} to $value for $dbname.\n" );

			$dbw->newUpdateQueryBuilder()
				->update( 'mw_namespaces' )
				->set( [ 'ns_namespace_name' => $value ] )
				->where( [
					'ns_dbname' => $dbname,
					'ns_namespace_id' => $ns->ns_namespace_id,
				] )
				->caller( __METHOD__ )
				->execute();
		}
	}
}

return FixProjectNS::class;
