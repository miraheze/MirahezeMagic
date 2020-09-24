<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class createWiki extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'createwiki' );
		$dbw = wfGetDB( DB_MASTER, [], $this->config->get( 'CreateWikiDatabase' ) );

		$dbw->insert(
			'cw_wikis',
			[
				'wiki_dbname' => 'ldapwikiwiki',
				'wiki_dbcluster' => 'c2',
				'wiki_sitename' => 'LDAP Wiki',
				'wiki_language' => 'en',
				'wiki_private' => 0,
				'wiki_creation' => $dbw->timestamp(),
				'wiki_category' => 'uncategorised'
			]
		);
		
		$this->recacheWikiJson( 'ldapwikiwiki' );
	}

	private function recacheWikiJson( string $wiki ) {
		$cWJ = new CreateWikiJson( $wiki );
		$cWJ->resetWiki();
		$cWJ->update();
	}

}

$maintClass = 'createWiki';
require_once RUN_MAINTENANCE_IF_MAIN;
