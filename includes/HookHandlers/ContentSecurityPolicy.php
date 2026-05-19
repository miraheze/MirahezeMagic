<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Hook\ContentSecurityPolicyDirectivesHook;

class ContentSecurityPolicy implements ContentSecurityPolicyDirectivesHook {

	public function __construct(
		private readonly Config $config
	) {
	}

	/**
	 * @inheritDoc
	 * @param $policyConfig @phan-unused-param
	 * @param $mode @phan-unused-param
	 */
	public function onContentSecurityPolicyDirectives( &$directives, $policyConfig, $mode ): void {
		// Completely nuke the original directives and replace with Miraheze ones
		$directives = [];
		$defaultDirectives = $this->config->get( 'MirahezeMagicCSPHeaderDefault' );
		$overrides = $this->config->get( 'MirahezeMagicCSPHeaderOverrides' );

		foreach ( $defaultDirectives as $name => $value ) {
			// Add domains from overrides if present
			if ( isset( $overrides[$name] ) ) {
				$value = array_unique( array_merge( $value, $overrides[$name] ) );
			}
			$directives[$name] = $name . ' ' . implode( ' ', $value );
		}
	}
}
