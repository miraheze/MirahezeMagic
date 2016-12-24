<?php
class MirahezeMagicHooks {
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
			'privacypage',
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
	* Helper for adding the Piwik code to the footer.
	*
	* @param Skin $skin Skin object
	* @param string &$text Output text.
	* @return bool
	*/
	public function piwikScript( $skin, &$text = '' ) {
		global $wmgPiwikSiteID, $wgUser, $wgDBname;
		if ( !$wmgPiwikSiteID ) {
			$wmgPiwikSiteID = 1;
		}

		$id = strval( $wmgPiwikSiteID );
		$title = $skin->getRelevantTitle();
		$jstitle = Xml::encodeJsVar( $title->getPrefixedText() );
		$dbname = Xml::encodeJsVar( $wgDBname );
		$urltitle = $title->getPrefixedURL();
		$userType = $wgUser->isLoggedIn() ? "User" : "Anonymous";
		$text .= <<<SCRIPT
			<!-- Piwik -->
			<script type="text/javascript">
			var _paq = _paq || [];
			_paq.push(["trackPageView"]);
			_paq.push(["enableLinkTracking"]);
			(function() {
				var u = "//piwik.miraheze.org/";
				_paq.push(["setTrackerUrl", u + "piwik.php"]);
				_paq.push(['setDocumentTitle', {$dbname} + " - " + {$jstitle}]);
				_paq.push(["setSiteId", "{$id}"]);
				_paq.push(["setCustomVariable", 1, "userType", "{$userType}", "visit"]);
				var d=document, g=d.createElement("script"), s=d.getElementsByTagName("script")[0]; g.type="text/javascript";
				g.defer=true; g.async=true; g.src=u+"piwik.js"; s.parentNode.insertBefore(g,s);
			})();
			</script>
			<!-- End Piwik Code -->
			<!-- Piwik Image Tracker -->
			<noscript>
				<img src="//piwik.miraheze.org/piwik.php?idsite={$id}&amp;rec=1&amp;action_name={$urltitle}" style="border:0" alt="" />
			</noscript>
			<!-- End Piwik -->
SCRIPT;
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

