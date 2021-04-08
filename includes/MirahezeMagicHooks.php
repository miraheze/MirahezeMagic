<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

class MirahezeMagicHooks {
	/**
	 * Avoid filtering automatic account creation
	 *
	 * @param AbuseFilterVariableHolder $vars
	 * @param Title $title
	 * @param User $user
	 * @param array &$skipReasons
	 * @return bool
	 */
	public static function onAbuseFilterShouldFilterAction(
		AbuseFilterVariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		$action = $vars->getVar( 'action' )->toString();
		if ( $action === 'autocreateaccount' ) {
			$skipReasons[] = "Blocking automatic account creation is not allowed";
			return false;
		}
		return true;
	}

	public static function onCreateWikiCreation( $DBname ) {
		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Create static directory for wiki
		if ( !file_exists( "/mnt/mediawiki-static/{$DBname}" ) ) {
			Shell::command( '/bin/mkdir', '-p', "/mnt/mediawiki-static/{$DBname}" )
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		// Copy SocialProfile images
		if ( file_exists( "/mnt/mediawiki-static/{$DBname}" ) ) {
			Shell::command(
				'/bin/cp',
				'-r',
				'/srv/mediawiki/w/extensions/SocialProfile/avatars',
				"/mnt/mediawiki-static/{$DBname}/avatars"
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();

			Shell::command(
				'/bin/cp',
				'-r',
				'/srv/mediawiki/w/extensions/SocialProfile/awards',
				"/mnt/mediawiki-static/{$DBname}/awards"
			)
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}
	}

	public static function onCreateWikiDeletion( $dbw, $wiki ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$dbw = wfGetDB( DB_MASTER, [], $config->get( 'EchoSharedTrackingDB' ) );

		$dbw->delete( 'echo_unread_wikis', [ 'euw_wiki' => $wiki ] );

		if ( file_exists( "/mnt/mediawiki-static/$wiki" ) ) {
			Shell::command( '/bin/rm', '-rf', "/mnt/mediawiki-static/$wiki" )
				->limits( [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ] )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		static::removeRedisKey( "*{$wiki}*" );
		// static::removeMemcachedKey( ".*{$wiki}.*" );
	}

	public static function onCreateWikiRename( $dbw, $old, $new ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$dbw = wfGetDB( DB_MASTER, [], $config->get( 'EchoSharedTrackingDB' ) );

		$dbw->update( 'echo_unread_wikis', [ 'euw_wiki' => $new ], [ 'euw_wiki' => $old ] );

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		if ( file_exists( "/mnt/mediawiki-static/{$old}" ) ) {
			Shell::command( '/bin/mv', "/mnt/mediawiki-static/{$old}", "/mnt/mediawiki-static/{$new}" )
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		} else if ( file_exists( "/mnt/mediawiki-static/private/{$old}" ) ) {
			Shell::command( '/bin/mv', "/mnt/mediawiki-static/private/{$old}", "/mnt/mediawiki-static/private/{$new}" )
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		static::removeRedisKey( "*{$old}*" );
		// static::removeMemcachedKey( ".*{$old}.*" );
	}

	public static function onCreateWikiStatePrivate( $dbname ) {
		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];
		
		if ( file_exists( "/mnt/mediawiki-static/{$dbname}/sitemaps" ) ) {
			Shell::command( '/bin/rm', '-rf', "/mnt/mediawiki-static/{$dbname}/sitemaps" )
				->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}
	}

	public static function onCreateWikiTables( &$tables ) {
		$tables['localnames'] = 'ln_wiki';
		$tables['localuser'] = 'lu_wiki';
	}

	/**
	* From WikimediaMessages. Allows us to add new messages,
	* and override ones.
	*
	* @param string &$lcKey Key of message to lookup.
	* @return bool
	*/
	public static function onMessageCacheGet( &$lcKey ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		static $keys = [
			'centralauth-groupname',
			'dberr-again',
			'privacypage',
			'prefs-help-realname',
			'shoutwiki-loginform-tos',
			'shoutwiki-must-accept-tos',
			'oathauth-step1',
			'centralauth-merge-method-admin-desc',
			'centralauth-merge-method-admin',
			'restriction-protect',
			'restriction-delete',
			'wikibase-sitelinks-miraheze',
			'centralauth-login-error-locked',
		];

		if ( in_array( $lcKey, $keys, true ) ) {
			$prefixedKey = "miraheze-$lcKey";
			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $lcKey );
			$cache = MediaWikiServices::getInstance()->getMessageCache();

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
			$cache->getMsgFromNamespace( $ucKey, $config->get( 'LanguageCode' ) ) === false
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

		if( $target[0] == '0' ) {
			array_shift( $target );
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

		$attribs = [
			'href' => $linkURL,
			'class' => 'extiw',
			'title' => $tooltip
		];

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
			'Notifications',
			'OAuth',
			'ResetPassword'
		];

		if ( $title->isSpecialPage() ) {
			$rootName = strtok( $title->getText(), '/' );
			$rootTitle = Title::makeTitle( $title->getNamespace(), $rootName );

			foreach ( $specialsArray as $page ) {
				if ( $rootTitle->equals( SpecialPage::getTitleFor( $page ) ) ) {
					$whitelisted = true;
					return;
				}
			}
		}
	}

	public static function onGlobalUserPageWikis( &$list ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		$cwCacheDir = $config->get( 'CreateWikiCacheDirectory' );
		if ( file_exists( "{$cwCacheDir}/databases.json" ) ) {
			$databasesArray = json_decode( file_get_contents( "{$cwCacheDir}/databases.json" ), true );
			$list = array_keys( $databasesArray['combi'] );
			return false;
		}

		return true;
	}

	/** Removes redis keys for jobrunner **/
	public static function removeRedisKey( string $key ) {
		global $wmgCacheSettings;

		$redisServer = explode( ':', $wmgCacheSettings['jobrunner']['server'] );

		try {
			$redis = new Redis();
			$redis->connect( $redisServer[0], $redisServer[1] );
			$redis->auth( $wmgCacheSettings['jobrunner']['password'] );
			$redis->del( $redis->keys( $key ) );
		} catch ( Exception $ex ) {
			// empty
		}
	}

	/** Remove memcached keys **/
	public static function removeMemcachedKey( string $key ) {
		global $wmgCacheSettings;

		$memcacheServer = explode( ':', $wmgCacheSettings['memcached']['server'][0] );

		try {
			$memcached = new \Memcached();
			$memcached->addServer( $memcacheServer[0], $memcacheServer[1] );

			// Fetch all keys
			$keys = $memcached->getAllKeys();
			if ( !is_array( $keys ) ) {
				return;
			}

			$memcached->getDelayed($keys);

			$store = $memcached->fetchAll();

			$keys = $memcached->getAllKeys();
			foreach( $keys as $item ) {
				// Decide which keys to delete
				if ( preg_match( "/{$key}/", $item ) ) {
					$memcached->delete( $item );
				} else {
					continue;
				}
			}
		} catch ( Exception $ex ) {
			// empty
		}
	}

	public static function onMimeMagicInit( $magic ) {
		$magic->addExtraTypes( 'text/plain txt off' );
	}

	public static function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key === 'places' ) {
			$footerItems['termsofservice'] = $skin->footerLink( 'termsofservice', 'termsofservicepage' );

			$footerItems['donate'] = $skin->footerLink( 'miraheze-donate', 'miraheze-donatepage' );
		}
	}

	public static function onUserGetRightsRemove( User $user, array &$aRights ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		// Remove read from stewards on staff wiki.
		if ( $config->get( 'DBname' ) === 'staffwiki' && $user->isRegistered() ) {
			$centralAuthUser = CentralAuthUser::getInstance( $user );
			if ( $centralAuthUser &&
			    $centralAuthUser->exists() &&
			    !in_array( $centralAuthUser->getId(), $config->get( 'MirahezeStaffAccessIds' ) )
			) {
				$aRights = array_unique( $aRights );
				unset( $aRights[array_search( 'read', $aRights )] );
			}
		}
	}

	public static function onSiteNoticeAfter( &$siteNotice, $skin ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
		$wiki = new RemoteWiki( $config->get( 'DBname' ) );

		if ( $wiki->isClosed() ) {
			if ( $wiki->isPrivate() ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed' )->parse() . '</span></div>';
			}
		} elseif ( $wiki->isInactive() && !$wiki->isInactiveExempt() ) {
			if ( $wiki->isPrivate() ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive' )->parse() . '</span></div>';
			}
		}
	}
}
