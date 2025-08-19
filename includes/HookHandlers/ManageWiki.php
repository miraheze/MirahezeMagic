<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use CdnPurgeJob;
use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\IContextSource;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use Miraheze\CreateWiki\ConfigNames;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\ManageWiki\Hooks\ManageWikiCoreAddFormFieldsHook;
use Miraheze\ManageWiki\Hooks\ManageWikiCoreFormSubmissionHook;
use function array_combine;
use function array_filter;
use function array_unique;
use function asort;
use function explode;
use function is_dir;
use const MW_VERSION;

class ManageWiki implements
	ManageWikiCoreAddFormFieldsHook,
	ManageWikiCoreFormSubmissionHook
{

	public function __construct(
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly ServiceOptions $options
	) {
	}

	public static function factory(
		Config $mainConfig,
		JobQueueGroupFactory $jobQueueGroupFactory
	): self {
		return new self(
			$jobQueueGroupFactory,
			new ServiceOptions(
				[
					ConfigNames::Subdomain,
					'MirahezeMagicAllowedDomains',
					'MirahezeMagicMediaWikiVersions',
				],
				$mainConfig
			)
		);
	}

	/**
	 * @inheritDoc
	 * @param bool $ceMW @phan-unused-param
	 */
	public function onManageWikiCoreAddFormFields(
		IContextSource $context,
		ModuleFactory $moduleFactory,
		string $dbname,
		bool $ceMW,
		array &$formDescriptor
	): void {
		$mwCore = $moduleFactory->core( $dbname );
		$versionParts = explode( '.', explode( '-', MW_VERSION )[0] );
		$mwVersion = $mwCore->getExtraFieldData( 'mediawiki-version',
			default: "$versionParts[0].$versionParts[1]"
		);

		$versions = array_unique( array_filter(
			$this->options->get( 'MirahezeMagicMediaWikiVersions' ),
			static fn ( string $version ): bool => $mwVersion === $version ||
				is_dir( "/srv/mediawiki/$version" )
		) );

		asort( $versions );

		$allowedDomains = $this->options->get( 'MirahezeMagicAllowedDomains' );
		$subdomain = $this->options->get( ConfigNames::Subdomain );
		$formDescriptor['primary-domain'] = [
			'label-message' => 'miraheze-label-managewiki-primary-domain',
			'type' => 'select',
			'options' => array_combine( $allowedDomains, $allowedDomains ),
			'default' => $mwCore->getExtraFieldData( 'primary-domain', default: $subdomain ),
			'disabled' => !$context->getAuthority()->isAllowed( 'managewiki-restricted' ),
			'cssclass' => 'ext-managewiki-infuse',
			'section' => 'main',
		];

		$mwSettings = $moduleFactory->settings( $dbname );
		$setList = $mwSettings->listAll();
		$formDescriptor['article-path'] = [
			'label-message' => 'miraheze-label-managewiki-article-path',
			'type' => 'select',
			'options-messages' => [
				'miraheze-label-managewiki-article-path-wiki' => '/wiki/$1',
				'miraheze-label-managewiki-article-path-root' => '/$1',
			],
			'default' => $setList['wgArticlePath'] ?? '/wiki/$1',
			'disabled' => !$context->getAuthority()->isAllowed( 'managewiki-restricted' ),
			'cssclass' => 'ext-managewiki-infuse',
			'section' => 'main',
		];

		$formDescriptor['mainpage-is-domain-root'] = [
			'label-message' => 'miraheze-label-managewiki-mainpage-is-domain-root',
			'type' => 'check',
			'default' => $setList['wgMainPageIsDomainRoot'] ?? false,
			'disabled' => !$context->getAuthority()->isAllowed( 'managewiki-restricted' ),
			'cssclass' => 'ext-managewiki-infuse',
			'section' => 'main',
		];

		$formDescriptor['mediawiki-version'] = [
			'label-message' => 'miraheze-label-managewiki-mediawiki-version',
			'type' => 'select',
			'options' => array_combine( $versions, $versions ),
			'default' => $mwVersion,
			'disabled' => !$context->getAuthority()->isAllowed( 'managewiki-restricted' ),
			'cssclass' => 'ext-managewiki-infuse',
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
		$mwCore = $moduleFactory->core( $dbname );
		$versionParts = explode( '.', explode( '-', MW_VERSION )[0] );
		$version = $mwCore->getExtraFieldData( 'mediawiki-version',
			default: "$versionParts[0].$versionParts[1]"
		);

		$mediawikiVersion = $formData['mediawiki-version'] ?? $version;
		if ( $mediawikiVersion !== $version && is_dir( "/srv/mediawiki/$mediawikiVersion" ) ) {
			$mwCore->setExtraFieldData(
				'mediawiki-version', $mediawikiVersion, default: $version
			);
		}

		$subdomain = $this->options->get( ConfigNames::Subdomain );
		$domain = $mwCore->getExtraFieldData( 'primary-domain', default: $subdomain );
		$primaryDomain = $formData['primary-domain'] ?? $domain;
		if ( $primaryDomain !== $domain ) {
			$mwCore->setExtraFieldData(
				'primary-domain', $primaryDomain, default: $domain
			);
		}

		$mwSettings = $moduleFactory->settings( $dbname );
		$articlePath = $mwSettings->list( 'wgArticlePath' ) ?? '/wiki/$1';
		if ( $formData['article-path'] !== $articlePath ) {
			$mwSettings->modify( [ 'wgArticlePath' => $formData['article-path'] ], default: '/wiki/$1' );
			$mwSettings->commit();

			$mwCore->trackChange( 'article-path', $articlePath, $formData['article-path'] );

			$server = $mwCore->getServerName();
			$this->jobQueueGroupFactory->makeJobQueueGroup( $dbname )->push(
				new CdnPurgeJob( [
					'urls' => [
						$server . '/wiki/',
						$server . '/wiki',
						$server . '/',
						$server,
					],
				] )
			);
		}

		$mainPageIsDomainRoot = $mwSettings->list( 'wgMainPageIsDomainRoot' ) ?? false;
		if ( $formData['mainpage-is-domain-root'] !== $mainPageIsDomainRoot ) {
			$mwSettings->modify( [ 'wgMainPageIsDomainRoot' => $formData['mainpage-is-domain-root'] ], default: false );
			$mwSettings->commit();

			$mwCore->trackChange( 'mainpage-is-domain-root',
				$mainPageIsDomainRoot,
				$formData['mainpage-is-domain-root']
			);
		}
	}
}
