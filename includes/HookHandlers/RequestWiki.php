<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Language\RawMessage;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;

class RequestWiki implements
	RequestWikiFormDescriptorModifyHook,
	RequestWikiQueueFormDescriptorModifyHook
{

	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		$nsfwField = [
			'label-message' => 'requestwiki-label-nsfw',
			'help-message' => 'requestwiki-help-nsfw',
			'type' => 'check',
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'bio',
			newKey: 'nsfw',
			newField: $nsfwField
		);

		$nsfwtextField = [
			'label-message' => 'requestwiki-label-nsfwtext',
			'hide-if' => [ '!==', 'nsfw', '1' ],
			'type' => 'text',
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'nsfw',
			newKey: 'nsfwtext',
			newField: $nsfwtextField
		);
		$sourceField = [
			'label-message' => 'requestwiki-label-source',
			'type' => 'check',
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'purpose',
			newKey: 'source',
			newField: $sourceField
		);

		$sourceurlField = [
			'label-message' => 'requestwiki-label-sourceurl',
			'hide-if' => [ '!==', 'source', '1' ],
			'type' => 'url',
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'source',
			newKey: 'sourceurl',
			newField: $sourceurlField
		);
	}

	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void {
		$nsfwField = [
			'label-message' => 'requestwiki-label-nsfw',
			'type' => 'check',
			'section' => 'editing',
			'default' => $wikiRequestManager->getExtraFieldData( 'nsfw' ),
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-bio',
			newKey: 'edit-nsfw',
			newField: $nsfwField
		);

		$nsfwtextField = [
			'label-message' => 'requestwiki-label-nsfwtext',
			'type' => 'text',
			'section' => 'editing',
			'hide-if' => [ '!==', 'edit-nsfw', '1' ],
			'default' => $wikiRequestManager->getExtraFieldData( 'nsfwtext' ),
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-nsfw',
			newKey: 'edit-nsfwtext',
			newField: $nsfwtextField
		);

		$nsfwField = [
			'label-message' => 'requestwiki-label-nsfw',
			'type' => 'info',
			'section' => 'details',
			'raw' => true,
			'default' => ( new RawMessage( ( $wikiRequestManager->getExtraFieldData( 'nsfw' ) ? '{{Done|Yes}}' : '{{Notdone|No}}' ) ) )->parse(), ];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'details-description',
			newKey: 'details-nsfw',
			newField: $nsfwField
		);

		$sourceField = [
			'label-message' => 'requestwiki-label-source',
			'type' => 'check',
			'section' => 'editing',
			'default' => $wikiRequestManager->getExtraFieldData( 'source' ),
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-purpose',
			newKey: 'edit-source',
			newField: $sourceField
		);

		$sourceurlField = [
			'label-message' => 'requestwiki-label-sourceurl',
			'type' => 'url',
			'section' => 'editing',
			'hide-if' => [ '!==', 'edit-source', '1' ],
			'default' => $wikiRequestManager->getExtraFieldData( 'sourceurl' ),
		];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-source',
			newKey: 'edit-sourceurl',
			newField: $sourceurlField
		);

		$sourceField = [
			'label-message' => 'requestwiki-label-source',
			'type' => 'info',
			'section' => 'details',
			'raw' => true,
			'default' => ( new RawMessage( ( $wikiRequestManager->getExtraFieldData( 'source' ) ? '{{Done|Yes}}' : '{{Notdone|No}}' ) ) )->parse(), ];

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'details-purpose',
			newKey: 'details-source',
			newField: $sourceField
		);
	}
}
