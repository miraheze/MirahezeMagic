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
		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'bio',
			newKey: 'nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'help-message' => 'requestwiki-help-nsfw',
				'help-inline' => false,
				'type' => 'check',
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'nsfw',
			newKey: 'nsfwtext',
			newField: [
				'label-message' => 'requestwiki-label-nsfwtext',
				'hide-if' => [ '!==', 'nsfw', '1' ],
				'type' => 'text',
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'purpose',
			newKey: 'source',
			newField: [
				'label-message' => 'requestwiki-label-source',
				'type' => 'check',
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'source',
			newKey: 'sourceurl',
			newField: [
				'label-message' => 'requestwiki-label-sourceurl',
				'hide-if' => [ '!==', 'source', '1' ],
				'type' => 'url',
			]
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'subdomain',
			newSection: 'core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'sitename',
			newSection: 'core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'language',
			newSection: 'core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'private',
			newSection: 'configure'
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'subdomain',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'sitename',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'private',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'bio',
			newProperties: [ 'help-inline' => false ]
		);
	}

	public function onRequestWikiQueueFormDescriptorModify(
		array &$formDescriptor,
		User $user,
		WikiRequestManager $wikiRequestManager
	): void {
		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-bio',
			newKey: 'edit-nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'type' => 'check',
				'section' => 'editing',
				'default' => $wikiRequestManager->getExtraFieldData( 'nsfw' ),
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-nsfw',
			newKey: 'edit-nsfwtext',
			newField: [
				'label-message' => 'requestwiki-label-nsfwtext',
				'type' => 'text',
				'section' => 'editing',
				'hide-if' => [ '!==', 'edit-nsfw', '1' ],
				'default' => $wikiRequestManager->getExtraFieldData( 'nsfwtext' ),
			]
		);

		$isNsfw = $wikiRequestManager->getExtraFieldData( 'nsfw' );
		$nsfwMessage = new RawMessage( $isNsfw ? '{{Done|Yes}}' : '{{Notdone|No}}' );

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'details-description',
			newKey: 'details-nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'type' => 'info',
				'section' => 'details',
				'raw' => true,
				'default' => $nsfwMessage->parse(),
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-purpose',
			newKey: 'edit-source',
			newField: [
				'label-message' => 'requestwiki-label-source',
				'type' => 'check',
				'section' => 'editing',
				'default' => $wikiRequestManager->getExtraFieldData( 'source' ),
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-source',
			newKey: 'edit-sourceurl',
			newField: [
				'label-message' => 'requestwiki-label-sourceurl',
				'type' => 'url',
				'section' => 'editing',
				'hide-if' => [ '!==', 'edit-source', '1' ],
				'default' => $wikiRequestManager->getExtraFieldData( 'sourceurl' ),
			]
		);

		$hasSource = $wikiRequestManager->getExtraFieldData( 'source' );
		$sourceMessage = new RawMessage( $hasSource ? '{{Done|Yes}}' : '{{Notdone|No}}' );

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'details-purpose',
			newKey: 'details-source',
			newField: [
				'label-message' => 'requestwiki-label-source',
				'type' => 'info',
				'section' => 'details',
				'raw' => true,
				'default' => $sourceMessage->parse(),
			]
		);
	}
}
