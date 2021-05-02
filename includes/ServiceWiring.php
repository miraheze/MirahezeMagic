<?php

use MediaWiki\MediaWikiServices;

/**
 * DI service wiring for the MirahezeMagic extension.
 */
return [
	'MirahezeMagic.LogEmailManager' => static function ( MediaWikiServices $services ) : MirahezeMagicLogEmailManager {
		return new MirahezeMagicLogEmailManager(
			$services->getConfigFactory()->makeConfig( 'mirahezemagic' )
		);
	},
];
