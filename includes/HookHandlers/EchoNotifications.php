<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\User\Hook\UserGetDefaultOptionsHook;
use Miraheze\MirahezeMagic\Notifications\EchoTechNotificationPresentationModel;

class EchoNotifications implements
	BeforeCreateEchoEventHook,
	UserGetDefaultOptionsHook
{

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications, array &$notificationCategories, array &$notificationIcons
	) {
		$notificationCategories['mirahezemagic-tech-notification'] = [
			'priority' => 3,
			'tooltip' => 'echo-pref-tooltip-mirahezemagic-tech-notification',
		];

		$notifications['mirahezemagic-tech-notification'] = [
			'category' => 'mirahezemagic-tech-notification',
			'group' => 'neutral',
			'section' => 'alert',
			'presentation-model' => EchoTechNotificationPresentationModel::class,
		];
	}

	/** @inheritDoc */
	public function onUserGetDefaultOptions( &$defaultOptions ) {
		$defaultOptions['echo-subscriptions-email-mirahezemagic-tech-notification'] = true;
	}

}
