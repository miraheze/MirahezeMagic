<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Hook\ContentSecurityPolicyDirectivesHook;
use MediaWiki\Registration\ExtensionRegistry;

class ContentSecurityPolicy implements ContentSecurityPolicyDirectivesHook {

	public function __construct(
		private readonly Config $config,
		private readonly ExtensionRegistry $extensionRegistry
	) {
	}

	/**
	 * @inheritDoc
	 *
	 * @param $policyConfig @phan-unused-param
	 * @param $mode @phan-unused-param
	 */
	public function onContentSecurityPolicyDirectives( &$directives, $policyConfig, $mode ): void {
		$csp = $this->config->get( 'MirahezeMagicCSPHeaderEssential' );
		$services = $this->config->get( 'MirahezeMagicCSPServices' );

		foreach ( $this->getEnabledServices() as $serviceName ) {
			$serviceCSP = $services[$serviceName]['sources'] ?? [];
			$csp = $this->mergeCSP( $csp, $serviceCSP );
		}

		// Wiki-specific overrides
		$csp = $this->mergeCSP( $csp, $this->config->get( 'MirahezeMagicCSPHeaderOverrides' ) );

		// Completely nuke the original directives and replace with Miraheze ones
		$directives = [];
		foreach ( $csp as $name => $value ) {
			$directives[$name] = $name . ' ' . implode( ' ', array_unique( $value ) );
		}
	}

	private function getEnabledServices(): array {
		$enabled = $this->config->get( 'MirahezeMagicCSPEnabledServices' );
		$extensionServices = $this->config->get( 'MirahezeMagicCSPHeaderExtensionServices' );

		foreach ( $extensionServices as $extension => $services ) {
			if ( $this->extensionRegistry->isLoaded( $extension ) ) {
				$enabled = array_merge( $enabled, $services );
			}
		}

		return array_unique( $enabled );
	}

	private function mergeCSP( array $csp1, array $csp2 ): array {
		foreach ( $csp2 as $name => $value ) {
			$csp1[$name] = array_merge( $csp1[$name] ?? [], $value );
		}

		return $csp1;
	}
}
