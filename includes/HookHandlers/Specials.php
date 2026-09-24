<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\SpecialPage\DisabledSpecialPage;
use MediaWiki\SpecialPage\Hook\SpecialPage_initListHook;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\Specials\SpecialEmailUser;

// MediaWiki core uses SpecialPage_initList as the hook name.
// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

class Specials implements SpecialPageBeforeExecuteHook, SpecialPage_initListHook {

	public function __construct() {
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
	}
}
