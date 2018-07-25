<?php
class MirahezeMagicHooks {
	public static function onCreateWikiCreation( $DBname ) {
		$backend = FileBackendGroup::singleton()->get(  'local' );
		$extensions = [ 's', 'm', 'ml', 'l' ];
		foreach ( $extensions as $size ) {
			$backend->quickStore( [
				'src' => '/srv/mediawiki/w/extensions/SocialProfile/avatars/default_' . $size . '.gif',
				'dst' => '/mnt/mediawiki-static/' . $DBname . '/avatars/default_' . $size . '.gif',
			] );
		}
	}

	/**
	* From WikimediaMessages. Allows us to add new messages,
	* and override ones.
	*
	* @param string &$lcKey Key of message to lookup.
	* @return bool
	*/
	public static function onMessageCacheGet( &$lcKey ) {
		global $wgLanguageCode;
		static $keys = array(
			'centralauth-groupname',
			'dberr-again',
			'privacypage',
			'prefs-help-realname',
			'shoutwiki-loginform-tos',
			'shoutwiki-must-accept-tos',
			'oathauth-step1',
		);

		if ( in_array( $lcKey, $keys, true ) ) {
			$prefixedKey = "miraheze-$lcKey";
			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $lcKey );
			$cache = MessageCache::singleton();

			if (
			// Override order:
			// 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
			// (in all languages) with normal fallback order.  Specific
			// language pages (MediaWiki:$ucKey/xy) are not checked when
			// deciding which key to use, but are still used if applicable
			// after the key is decided.
			//
			// 2. Otherwise, use the prefixed key with normal fallback order
			// (including MediaWiki pages if they exist).
			$cache->getMsgFromNamespace( $ucKey, $wgLanguageCode ) === false
			) {
				$lcKey = $prefixedKey;
			}
		}

		return true;
	}


	/**
	* Allows to use Special:Central(Auto)Login on private wikis..
	*
	* @param Title $title Title object
	* @param User $user User object
	* @param bool &$whitelisted Is the page whitelisted?
	*/
	public function onTitleReadWhitelist( $title, $user, &$whitelisted ) {
		global $wgContLang;

		$regexLine = "/^" . preg_quote( $wgContLang->getNsText( NS_SPECIAL ), '/' ) . ":Central(Auto)?Login/i";

		if ( preg_match( $regexLine, $title->getPrefixedDBKey() ) === 1 ) {
			$whitelisted = true;
		}
	}

	/**
	* Helper for adding the Piwik code to the footer.
	*
	* @param array &$vars Current list of vars
	* @param OutputPage $out OutputPage object
	*/
	public static function onMakeGlobalVariablesScript( &$vars, OutputPage $out ) {
		if ( defined( 'HHVM_VERSION' ) ) {
			$vars['wgPoweredByHHVM'] = true;
		}
	}
}

