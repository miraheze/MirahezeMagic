<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\Specials\SpecialEmailUser;

class Specials implements SpecialPageBeforeExecuteHook {

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
}
