<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Hook\MediaWikiServicesHook;
use MediaWiki\SpecialPage\DisabledSpecialPage;
use MediaWiki\SpecialPage\Hook\SpecialPage_initListHook;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\Specials\SpecialEmailUser;

// MediaWiki core uses SpecialPage_initList as the hook name.
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

class Specials implements MediaWikiServicesHook, SpecialPageBeforeExecuteHook, SpecialPage_initListHook {

	public function __construct() {
	}

	/** @inheritDoc */
	public function onMediaWikiServices( $services ): void {
		// We need to make sure that the hook handler is registered after the one in CentralAuth,
		// but before core invokes the SpecialPage_initList hook when visiting a special page.
		$services->getHookContainer()->register(
			'SpecialPage_initList',
			$this->onSpecialPage_initList( ... )
		);
	}

	/**
	 * @inheritDoc
	 * @param string|null $subPage @phan-unused-param
	 * @throws ErrorPageError If the user is not allowed to send emails
	 */
	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		if ( !( $special instanceof SpecialEmailUser ) ) {
			return true;
		}

		if ( $special->getAuthority()->isAllowed( 'sendemail' ) ) {
			return true;
		}

		throw new ErrorPageError( 'miraheze-emailuser-disabled-title', 'miraheze-emailuser-disabled-message' );
	}

	/** @inheritDoc */
	public function onSpecialPage_initList( &$list ) {
		if ( !isset( $list['GlobalVanishRequest'] ) ) {
			return true;
		}

		$list['GlobalVanishRequest'] = DisabledSpecialPage::getCallback( 'GlobalVanishRequest', 'miraheze-globalvanishrequest-disabled-message' );
		return true;
	}
}
