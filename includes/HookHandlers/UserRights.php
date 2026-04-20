<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Hook\UserGetRightsHook;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;

class UserRights implements UserGetRightsHook {

	public function __construct(
		private readonly Config $config,
		private readonly ExtensionRegistry $extensionRegistry
	) {
	}

	/** @inheritDoc
	 * @param User $user @phan-unused-param
	 */
	public function onUserGetRights( $user, &$rights ) {
		if ( $this->extensionRegistry->isLoaded( 'CentralAuth' ) ) {
			$hasGlobalRight = CentralAuthUser::getInstance( $user )->hasGlobalPermission( 'editallcustomprotected' );
		} else {
			$hasGlobalRight = false;
		}
		if ( in_array( 'editallcustomprotected', $rights ) || $hasGlobalRight ) {
			$levels = $this->config->get( MainConfigNames::RestrictionLevels );
			$toAdd = array_diff(
				$levels,
				[
					'',
					'user',
					'autoconfirmed',
					'sysop'
				]
			);
			$rights = array_unique(
				array_merge(
					$rights,
					$toAdd
				)
			);
		}
	}
}
