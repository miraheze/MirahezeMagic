<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Language\RawMessage;
use MediaWiki\User\User;
use Miraheze\CreateWiki\Hooks\CreateWikiAfterCreationWithExtraDataHook;
use Miraheze\CreateWiki\Hooks\RequestWikiFormDescriptorModifyHook;
use Miraheze\CreateWiki\Hooks\RequestWikiQueueFormDescriptorModifyHook;
use Miraheze\CreateWiki\RequestWiki\RequestWikiFormUtils;
use Miraheze\CreateWiki\Services\WikiRequestManager;
use Miraheze\ManageWiki\Helpers\ManageWikiExtensions;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;

class RequestWiki implements
	CreateWikiAfterCreationWithExtraDataHook,
	RequestWikiFormDescriptorModifyHook,
	RequestWikiQueueFormDescriptorModifyHook
{

	public function onRequestWikiFormDescriptorModify( array &$formDescriptor ): void {
		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'help-message' => 'requestwiki-help-nsfw',
				'help-inline' => false,
				'section' => 'info',
				'type' => 'check',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'nsfwtext',
			newField: [
				'label-message' => 'requestwiki-label-nsfwtext',
				'hide-if' => [ '!==', 'nsfw', '1' ],
				'section' => 'info',
				'type' => 'text',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'source',
			newField: [
				'label-message' => 'requestwiki-label-source',
				'section' => 'info',
				'type' => 'check',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'sourceurl',
			newField: [
				'label-message' => 'requestwiki-label-sourceurl',
				'hide-if' => [ '!==', 'source', '1' ],
				'section' => 'info',
				'type' => 'url',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'defaultskin',
			newField: [
				'type' => 'select',
				'label-message' => 'requestwiki-label-defaultskin',
				'options' => [ 'vector-2022' => 'vector-2022' ],
				'default' => 'vector-2022',
				'section' => 'configure',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'showadvanced',
			newField: [
				'type' => 'check',
				'label-message' => 'requestwiki-label-showadvanced',
				'section' => 'advanced',
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'articlepath',
			newField: [
				'type' => 'radio',
				'label-message' => 'miraheze-label-managewiki-article-path',
				'options-messages' => [
					'miraheze-label-managewiki-article-path-wiki' => '/wiki/$1',
					'miraheze-label-managewiki-article-path-root' => '/$1',
				],
				'hide-if' => [ '!==', 'showadvanced', '1' ],
				'default' => '/wiki/$1',
				'section' => 'advanced',
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
			fieldKey: 'category',
			newSection: 'configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'private',
			newSection: 'configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'guidance',
			newSection: 'info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'purpose',
			newSection: 'info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'reason',
			newSection: 'info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'bio',
			newSection: 'info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'post-reason-guidance',
			newSection: 'confirmation'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'agreement',
			newSection: 'confirmation'
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'category',
			newProperties: [ 'help-inline' => false ]
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

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'core',
			newOrder: [
				'subdomain',
				'sitename',
				'language',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'info',
			newOrder: [
				'guidance',
				'purpose',
				'bio',
				'nsfw',
				'source',
				'nsfwtext',
				'sourceurl',
				'reason',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'configure',
			newOrder: [
				'private',
				'category',
				'defaultskin',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'advanced',
			newOrder: [
				'showadvanced',
				'articlepath',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'confirmation',
			newOrder: [
				'post-reason-guidance',
				'agreement',
			]
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

	public function onCreateWikiAfterCreationWithExtraData( array $extraData, string $dbname ): void {
		$mwSettings = new ManageWikiSettings( $dbname );
		$setList = $mwSettings->list();

		$mwExtensions = new ManageWikiExtensions( $dbname );
		$extList = $mwExtensions->list();

		if ( $extraData['articlepath'] !== ( $setList['wgArticlePath'] ?? '/wiki/$1' ) ) {
			$mwSettings->modify( [ 'wgArticlePath' => $extraData['articlepath'] ] );
			$mwSettings->commit();
		}

		if ( $extraData['defaultskin'] !== ( $setList['wgDefaultSkin'] ?? 'vector-2022' ) ) {
			if (
				!isset( $extList[ $extraData['defaultskin'] ] ) &&
				!in_array(
					$extraData['defaultskin'],
					[ 'vector', 'vector-2022' ]
				)
			) {
				$mwExtensions->add( $extraData['defaultskin'] );
				$mwExtensions->commit();
			}

			$mwSettings->modify( [ 'wgDefaultSkin' => $extraData['defaultskin'] ] );
			$mwSettings->commit();
		}
	}
}
