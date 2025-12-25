<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\User\User;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\CreateWikiCreationExtraFieldsHook;
use Miraheze\CreateWiki\RequestWiki\FormFields\DetailsWithIconField;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;

class RequestWiki implements
	CreateWikiCreationExtraFieldsHook,
	RequestWikiFormDescriptorModifyHook,
	RequestWikiQueueFormDescriptorModifyHook
{

	/** @inheritDoc */
	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'bio',
			newKey: 'nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'help-message' => 'requestwiki-help-nsfw',
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
			afterKey: 'nsfwtext',
			newKey: 'nsfw-primary',
			newField: [
				'label-message' => 'requestwiki-label-nsfw-primary',
				'help-message' => 'requestwiki-help-nsfw-primary',
				'hide-if' => [ '!==', 'nsfw', '1' ],
				'type' => 'check',
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
	}

	/**
	 * @inheritDoc
	 * @param User $user @phan-unused-param
	 */
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

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'edit-nsfwtext',
			newKey: 'edit-nsfw-primary',
			newField: [
				'label-message' => 'requestwiki-label-nsfw-primary',
				'type' => 'check',
				'section' => 'editing',
				'hide-if' => [ '!==', 'edit-nsfw', '1' ],
				'default' => $wikiRequestManager->getExtraFieldData( 'nsfwtext' ),
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

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'bio',
			newKey: 'nsfw',
			newField: [
				'class' => DetailsWithIconField::class,
				'label-message' => 'requestwiki-label-nsfw',
				'fieldCheck' => $wikiRequestManager->getExtraFieldData( 'nsfw' ),
				'section' => 'details',
			]
		);

		RequestWikiFormUtils::insertFieldAfter(
			$formDescriptor,
			afterKey: 'nsfw',
			newKey: 'source',
			newField: [
				'class' => DetailsWithIconField::class,
				'label-message' => 'requestwiki-label-source',
				'fieldCheck' => $wikiRequestManager->getExtraFieldData( 'source' ),
				'section' => 'details',
			]
		);
	}
	public function onCreateWikiCreationExtraFields( array &$extraFields ): void {
        $extraFields['nsfw-primary'] = true;
	}
}
