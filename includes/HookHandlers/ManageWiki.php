<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\ManageWiki\Hooks\ManageWikiCoreAddFormFieldsHook;
use Miraheze\ManageWiki\Hooks\ManageWikiCoreFormSubmissionHook;

class ManageWiki implements
	ManageWikiCoreAddFormFieldsHook,
	ManageWikiCoreFormSubmissionHook
{

	public function __construct(
		private readonly Config $config
	) {
	}

	/**
	 * @inheritDoc
	 * @param IContextSource $context @phan-unused-param
	 */
	public function onManageWikiCoreAddFormFields(
		IContextSource $context,
		ModuleFactory $moduleFactory,
		string $dbname,
		bool $ceMW,
		array &$formDescriptor
	): void {
		$mwCore = $moduleFactory->core( $dbname );

		$formDescriptor['nsfw-primary'] = [
			'label-message' => 'requestwiki-label-nsfw-primary',
			'type' => 'check',
			'default' => $mwCore->getExtraFieldData( 'nsfw-primary', default: '' ),
			'disabled' => !$ceMW,
			'section' => 'main',
		];
	}

	/**
	 * @inheritDoc
	 * @param IContextSource $context @phan-unused-param
	 */
	public function onManageWikiCoreFormSubmission(
		IContextSource $context,
		ModuleFactory $moduleFactory,
		string $dbname,
		array $formData
	): void {
		if ( !isset( $formData['nsfw-primary'] ) ) {
			return;
		}

		$mwCore = $moduleFactory->core( $dbname );
		$mwCore->setExtraFieldData(
		'nsfw-primary', $formData['nsfw-primary'], default: ''
		);
	}
}
