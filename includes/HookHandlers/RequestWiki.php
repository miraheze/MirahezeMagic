<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
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

	private ServiceOptions $options;

	public function __construct( ServiceOptions $options ) {
		$this->options = $options;
	}

	public static function factory( Config $mainConfig ): self {
		return new self(
			new ServiceOptions(
				[
					'ManageWikiExtensions',
					'MirahezeMagicRequestWikiExtensions',
					'MirahezeMagicRequestWikiSkins',
				],
				$mainConfig
			)
		);
	}

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
				'options' => array_combine(
					$this->options->get( 'MirahezeMagicRequestWikiSkins' ),
					$this->options->get( 'MirahezeMagicRequestWikiSkins' )
				),
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

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'defaultextensions',
			newField: [
				'type' => 'multiselect',
				'label-message' => 'requestwiki-label-defaultextensions',
				'help-message' => 'requestwiki-help-defaultextensions',
				'help-inline' => false,
				'options' => array_combine(
					$this->options->get( 'MirahezeMagicRequestWikiExtensions' ),
					$this->options->get( 'MirahezeMagicRequestWikiExtensions' )
				),
				'hide-if' => [ '!==', 'showadvanced', '1' ],
				'dropdown' => true,
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
				'defaultextensions',
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
		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-nsfw',
			newField: [
				'label-message' => 'requestwiki-label-nsfw',
				'help-message' => 'requestwiki-help-nsfw',
				'help-inline' => false,
				'section' => 'editing/info',
				'type' => 'check',
				'default' => $wikiRequestManager->getExtraFieldData( 'nsfw' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-nsfwtext',
			newField: [
				'label-message' => 'requestwiki-label-nsfwtext',
				'hide-if' => [ '!==', 'edit-nsfw', '1' ],
				'section' => 'editing/info',
				'type' => 'text',
				'default' => $wikiRequestManager->getExtraFieldData( 'nsfwtext' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-source',
			newField: [
				'label-message' => 'requestwiki-label-source',
				'section' => 'editing/info',
				'type' => 'check',
				'default' => $wikiRequestManager->getExtraFieldData( 'source' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-sourceurl',
			newField: [
				'label-message' => 'requestwiki-label-sourceurl',
				'hide-if' => [ '!==', 'edit-source', '1' ],
				'section' => 'editing/info',
				'type' => 'url',
				'default' => $wikiRequestManager->getExtraFieldData( 'sourceurl' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-defaultskin',
			newField: [
				'type' => 'select',
				'label-message' => 'requestwiki-label-defaultskin',
				'options' => array_combine(
					$this->options->get( 'MirahezeMagicRequestWikiSkins' ),
					$this->options->get( 'MirahezeMagicRequestWikiSkins' )
				),
				'section' => 'editing/configure',
				'default' => $wikiRequestManager->getExtraFieldData( 'defaultskin' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-showadvanced',
			newField: [
				'type' => 'check',
				'label-message' => 'requestwiki-label-showadvanced',
				'section' => 'editing/advanced',
				'default' => $wikiRequestManager->getExtraFieldData( 'showadvanced' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-articlepath',
			newField: [
				'type' => 'radio',
				'label-message' => 'miraheze-label-managewiki-article-path',
				'options-messages' => [
					'miraheze-label-managewiki-article-path-wiki' => '/wiki/$1',
					'miraheze-label-managewiki-article-path-root' => '/$1',
				],
				'hide-if' => [ '!==', 'edit-showadvanced', '1' ],
				'section' => 'editing/advanced',
				'default' => $wikiRequestManager->getExtraFieldData( 'articlepath' ),
			]
		);

		RequestWikiFormUtils::addFieldToEnd(
			$formDescriptor,
			newKey: 'edit-defaultextensions',
			newField: [
				'type' => 'multiselect',
				'label-message' => 'requestwiki-label-defaultextensions',
				'help-message' => 'requestwiki-help-defaultextensions',
				'help-inline' => false,
				'options' => array_combine(
					$this->options->get( 'MirahezeMagicRequestWikiExtensions' ),
					$this->options->get( 'MirahezeMagicRequestWikiExtensions' )
				),
				'hide-if' => [ '!==', 'edit-showadvanced', '1' ],
				'dropdown' => true,
				'section' => 'editing/advanced',
				'default' => $wikiRequestManager->getExtraFieldData( 'defaultextensions' ),
			]
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-subdomain',
			newSection: 'editing/core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-sitename',
			newSection: 'editing/core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-language',
			newSection: 'editing/core'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-category',
			newSection: 'editing/configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-private',
			newSection: 'editing/configure'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-purpose',
			newSection: 'editing/info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-reason',
			newSection: 'editing/info'
		);

		RequestWikiFormUtils::moveFieldToSection(
			$formDescriptor,
			fieldKey: 'edit-bio',
			newSection: 'editing/info'
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'edit-category',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'edit-subdomain',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'edit-sitename',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'edit-private',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::updateFieldProperties(
			$formDescriptor,
			fieldKey: 'edit-bio',
			newProperties: [ 'help-inline' => false ]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/core',
			newOrder: [
				'edit-subdomain',
				'edit-sitename',
				'edit-language',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/info',
			newOrder: [
				'edit-purpose',
				'edit-bio',
				'edit-nsfw',
				'edit-source',
				'edit-nsfwtext',
				'edit-sourceurl',
				'edit-reason',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/configure',
			newOrder: [
				'edit-private',
				'edit-category',
				'edit-defaultskin',
			]
		);

		RequestWikiFormUtils::reorderFieldsInSection(
			$formDescriptor,
			section: 'editing/advanced',
			newOrder: [
				'edit-showadvanced',
				'edit-articlepath',
				'edit-defaultextensions',
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
					$this->options->get( 'ManageWikiExtensions' )
				)
			) {
				$mwExtensions->add( $extraData['defaultskin'] );
				$mwExtensions->commit();
			}

			$mwSettings->modify( [ 'wgDefaultSkin' => $extraData['defaultskin'] ] );
			$mwSettings->commit();
		}

		if ( $extraData['defaultextensions'] ?? [] ) {
			$mwExtensions->add( $extraData['defaultextensions'] );
			$mwExtensions->commit();
		}
	}
}
