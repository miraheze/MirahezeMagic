<?php

use MediaWiki\MediaWikiServices;

class MirahezeMagic {
	public static function getConfig( $config ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		return $config->get( $config );
	}
}
