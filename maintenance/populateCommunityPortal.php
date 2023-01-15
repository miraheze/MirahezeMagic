<?php

namespace Miraheze\CreateWiki\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use CommentStoreComment;
use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use Title;
use User;
use WikitextContent;

class PopulateCommunityPortal extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Populates the Community portal page of a new wiki.' );
		$this->addOption( 'lang', 'Language of the Community portal, otherwise defaults to the wiki\'s language.', false );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'CreateWiki' );
		$language = $this->getOption( 'lang', $config->get( 'LanguageCode' ) );

		$communityPortalName = wfMessage( 'portal' )->inLanguage( $language )->plain();
		$title = Title::newFromText( $communityPortalName, $defaultNamespace = NS_PROJECT );
		$article = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title )->newPageUpdater( User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] ) );
		$article->setContent( SlotRecord::MAIN, new WikitextContent( wfMessage( 'miraheze-communityportal-text' )->inLanguage( $language )->plain() ) );
		$article->saveRevision( CommentStoreComment::newUnsavedComment( wfMessage( 'miraheze-communityportal-summary' )->inLanguage( $language )->plain() ), EDIT_SUPPRESS_RC );
	}
}

$maintClass = PopulateCommunityPortal::class;
require_once RUN_MAINTENANCE_IF_MAIN;
