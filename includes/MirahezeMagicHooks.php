<?php
use MediaWiki\Shell\Shell;

class MirahezeMagicHooks {
	public static function onCreateWikiCreation( $DBname ) {
		// Create static directory for wiki
		if ( !file_exists( "/mnt/mediawiki-static/$DBname" ) ) {
			Shell::command( '/bin/mkdir', '-p', "/mnt/mediawiki-static/$DBname" )->execute();
		}

		// Copy SocialProfile images
		if ( file_exists( "/mnt/mediawiki-static/$DBname" ) ) {
			Shell::command(
				'/bin/cp',
				'-r',
				'/srv/mediawiki/w/extensions/SocialProfile/avatars', 
				"/mnt/mediawiki-static/$DBname/avatars"
			)->execute();
			Shell::command(
				'/bin/cp',
				'-r',
				'/srv/mediawiki/w/extensions/SocialProfile/awards', 
				"/mnt/mediawiki-static/$DBname/awards"
			)->execute();
		}
		
		// actor table migration
		Shell::command(
			'/usr/bin/php',
			'/srv/mediawiki/w/maintenance/migrateActors.php',
			'--wiki',
			$DBname,
			'--force'
		)->execute();
		
		// Elasticsearch
		Shell::command(
			'/usr/bin/php',
			'/srv/mediawiki/w/extensions/CirrusSearch/maintenance/updateSearchIndexConfig.php',
			'--wiki',
			$DBname
		)->execute();
	}

	public static function onCreateWikiDeletion( $dbw, $wiki ) {
		if ( file_exists( "/mnt/mediawiki-static/$wiki" ) ) {
			Shell::command( '/bin/rm', '-rf', "/mnt/mediawiki-static/$wiki" )->execute();
		}

		$dbw->delete(
			'gnf_files',
			[
				'files_dbname' => $wiki,
			]
		);
	}

	public static function onCreateWikiRename( $dbw, $old, $new ) {

		if ( file_exists( "/mnt/mediawiki-static/$old" ) ) {
			Shell::command( '/bin/mv', "/mnt/mediawiki-static/$old", "/mnt/mediawiki-static/$new" )->execute();
		}

		$dbw->update(
			'gnf_files',
			[
				'files_dbname' => $new,
			],
			[
				'files_dbname' => $old,
			],
			__METHOD__
		);
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
	 * Enables global interwiki for [[mh:wiki:Page]]
	 */
	public static function onHtmlPageLinkRendererEnd( $linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret ) {
		$target = (string)$target;
		$tooltip = $target;
		$useText = true;

		$ltarget = strtolower( $target );
		$ltext = strtolower( HtmlArmor::getHtml( $text ) );

		if ( $ltarget == $ltext ) {
			$useText = false; // Allow link piping, but don't modify $text yet
		}

		$target = explode( ':', $target );

		if ( count( $target ) < 2 ) {
			return true; // Not enough parameters for interwiki
		}

		$prefix = strtolower( $target[0] );

		if ( $prefix != 'mh' ) {
			return true; // Not interesting
		}

		$wiki = strtolower( $target[1] );
		$target = array_slice( $target, 2 );
		$target = join( ':', $target );

		if ( !$useText ) {
			$text = $target;
		}
		if ( $text == '' ) {
			$text = $wiki;
		}

		$target = str_replace( ' ', '_', $target );
		$target = urlencode( $target );
		$linkURL = "https://$wiki.miraheze.org/wiki/$target";

		$attribs = array(
			'href' => $linkURL,
			'class' => 'extiw',
			'title' => $tooltip
		);

		return true;
	}

	/**
	 * Hard redirects all pages like Mh:Wiki:Page as global interwiki.
	 */
	public static function onInitializeArticleMaybeRedirect( $title, $request, &$ignoreRedirect, &$target, $article ) {
		$title = explode( ':', $title );
		$prefix = strtolower( $title[0] );

		if ( count( $title ) < 3 || $prefix !== 'mh' ) {
			return true;
		}

		$wiki = strtolower( $title[1] );
		$page = join( ':', array_slice( $title, 2 ) );
		$page = str_replace( ' ', '_', $page );
		$page = urlencode( $page );

		$target = "https://$wiki.miraheze.org/wiki/$page";

		return true;
	}

	public static function onTitleReadWhitelist( Title $title, User $user, &$whitelisted ) {
		if ( $title->equals( Title::newMainPage() ) ) {
			$whitelisted = true;
			return;
		}

		$specialsArray = [
			'CentralAutoLogin',
			'CentralLogin',
			'ConfirmEmail',
			'CreateAccount',
			'Interwiki',
			'Notifications',
			'ResetPassword'
		];

		foreach ( $specialsArray as $page ) {
			if ( $title->equals( SpecialPage::getTitleFor( $page ) ) ) {
				$whitelisted = true;
				return;
			}
		}
	}
}
