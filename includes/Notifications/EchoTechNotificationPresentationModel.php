<?php

namespace Miraheze\MirahezeMagic\Notifications;

use MediaWiki\Extension\Notifications\Formatters\EchoEventPresentationModel;
use MediaWiki\Language\RawMessage;
use MediaWiki\Skin\Skin;

class EchoTechNotificationPresentationModel extends EchoEventPresentationModel {

	/** @inheritDoc */
	public function getIconType(): string {
		return 'site';
	}

	/** @inheritDoc */
	public function getHeaderMessage() {
		return new RawMessage( $this->event->getExtraParam( 'header' ) );
	}

	/** @inheritDoc */
	public function getBodyMessage() {
		return new RawMessage( $this->event->getExtraParam( 'message' ) );
	}

	/** @inheritDoc */
	public function getPrimaryLink(): array|bool {
		$link = $this->event->getExtraParam( 'link' );
		$linkLabel = $this->event->getExtraParam( 'link-label' );
		if ( !$link || !$linkLabel ) {
			return false;
		}
		return [
			'url' => Skin::makeInternalOrExternalUrl( $link ),
			'label' => $linkLabel,
		];
	}

}
