<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\SpecialPage\Hook\SpecialPageBeforeExecuteHook;
use MediaWiki\Specials\SpecialEmailUser;

class Specials implements SpecialPageBeforeExecuteHook {

	public function __construct(
		private readonly PermissionManager $permissionManager,
	) {
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

		if ( $this->permissionManager->userHasRight( $special->getUser(), 'sendemail' ) ) {
			return true;
		}

		throw new ErrorPageError( 'miraheze-emailuser-disabled-title', 'miraheze-emailuser-disabled-message' );
	}
}
