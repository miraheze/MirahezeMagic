<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\GlobalUserPage\Hooks\GlobalUserPageWikisHook;
use Miraheze\CreateWiki\Services\CreateWikiDataStore;
use Miraheze\ManageWiki\Helpers\Factories\DataStoreFactory;

class GlobalUserPage implements GlobalUserPageWikisHook {

	public function __construct(
		private readonly CreateWikiDataStore $dataStore,
		private readonly DataStoreFactory $dataStoreFactory
	) {
	}

	/** @inheritDoc */
	public function onGlobalUserPageWikis( array &$list ): bool {
		$dbList = $this->dataStore->getAllDatabases();

		// Filter out those databases that don't have GlobalUserPage enabled
		$list = array_filter( $dbList, function ( string $dbname ): bool {
			$dataStore = $this->dataStoreFactory->newInstance( $dbname );
			return in_array( 'GlobalUserPage', $dataStore->getExtensions(), true );
		} );

		return false;
	}
}
