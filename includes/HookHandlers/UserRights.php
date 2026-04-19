<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\User\User;
use MediaWiki\MediaWikiServices;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Hook\UserGetRightsHook;


class UserRights implements UserGetRightsHook {

	public static function registerHooks(): void {
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		$handler = new self();
		$hookContainer->register(
			'UserGetRights',
			[
				$handler,
				'onUserGetRights'
			]
		);
	}

	/** @inheritDoc
	 * @param User $user @phan-unused-param
	 * */
	public function onUserGetRights( $user, &$rights ) {
		if ( in_array( 'editallcustomprotected', $rights ) ) {
			$config = MediaWikiServices::getInstance()->getMainConfig();
			$levels = $config->get( MainConfigNames::RestrictionLevels );
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
