<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Extension\AbuseFilter\Hooks\AbuseFilterShouldFilterActionHook;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\AbuseFilter\Variables\VariablesManager;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class AbuseFilter implements AbuseFilterShouldFilterActionHook {

	public function __construct(
		private readonly VariablesManager $variablesManager
	) {
	}

	/**
	 * Avoid filtering automatic account creation
	 *
	 * @inheritDoc
	 * @param Title $title @phan-unused-param
	 * @param User $user @phan-unused-param
	 */
	public function onAbuseFilterShouldFilterAction(
		VariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return;
		}

		$action = $this->variablesManager->getVar( $vars, 'action' )->toString();
		if ( $action === 'autocreateaccount' ) {
			$skipReasons[] = 'Blocking automatic account creation is not allowed';
			return false;
		}
	}
}
