<?php

use MediaWiki\Extension\AbuseFilter\AbuseFilterServices;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;
use Miraheze\ManageWiki\Helpers\ManageWikiSettings;
use Wikimedia\IPUtils;

class MirahezeMagicHooks {
	/**
	 * Avoid filtering automatic account creation
	 *
	 * @param VariableHolder $vars
	 * @param Title $title
	 * @param User $user
	 * @param array &$skipReasons
	 * @return bool
	 */
	public static function onAbuseFilterShouldFilterAction(
		VariableHolder $vars,
		Title $title,
		User $user,
		array &$skipReasons
	) {
		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return true;
		}

		$varManager = AbuseFilterServices::getVariablesManager();

		$action = $varManager->getVar( $vars, 'action', 1 )->toString();
		if ( $action === 'autocreateaccount' ) {
			$skipReasons[] = 'Blocking automatic account creation is not allowed';

			return false;
		}

		return true;
	}

	public static function onCreateWikiDeletion( $dbw, $wiki ) {
		global $wmgSwiftPassword;

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $config->get( 'EchoSharedTrackingDB' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $config->get( 'EchoSharedTrackingDB' ) );

		$dbw->delete( 'echo_unread_wikis', [ 'euw_wiki' => $wiki ] );

		foreach ( $config->get( 'LocalDatabases' ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $config->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $wiki
				) {
					$manageWikiSettings->remove( $var );
					$manageWikiSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to delete for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', 'miraheze-' . $wiki . '-',
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $wiki . '-' ) ) {
				continue;
			}

			// Delete the container
			Shell::command(
				'swift', 'delete',
				$container,
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();
		}

		static::removeRedisKey( "*{$wiki}*" );
		// static::removeMemcachedKey( ".*{$wiki}.*" );
	}

	public static function onCreateWikiRename( $dbw, $old, $new ) {
		global $wmgSwiftPassword;

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( $config->get( 'EchoSharedTrackingDB' ) )
			->getMaintenanceConnectionRef( DB_PRIMARY, [], $config->get( 'EchoSharedTrackingDB' ) );

		$dbw->update( 'echo_unread_wikis', [ 'euw_wiki' => $new ], [ 'euw_wiki' => $old ] );

		foreach ( $config->get( 'LocalDatabases' ) as $db ) {
			$manageWikiSettings = new ManageWikiSettings( $db );

			foreach ( $config->get( 'ManageWikiSettings' ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$manageWikiSettings->list( $var ) === $old
				) {
					$manageWikiSettings->modify( [ $var => $new ] );
					$manageWikiSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to download, and later upload for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', 'miraheze-' . $old . '-',
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $old . '-' ) ) {
				continue;
			}

			// Get a list of all files in the container to ensure everything is present in new container later.
			$oldContainerList = Shell::command(
				'swift', 'list',
				$container,
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout();

			// Download the container
			Shell::command(
				'swift', 'download',
				$container,
				'-D', wfTempDir() . '/' . $container,
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute();

			$newContainer = str_replace( $old, $new, $container );

			// Upload to new container
			// We have to use exec here, as Shell::command does not work for this
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
			exec( escapeshellcmd(
				implode( ' ', [
					'swift', 'upload',
					$newContainer,
					wfTempDir() . '/' . $container,
					'--object-name', '""',
					'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
					'-U', 'mw:media',
					'-K', $wmgSwiftPassword
				] )
			) );

			wfDebugLog( 'MirahezeMagic', "Container '$newContainer' created." );

			$newContainerList = Shell::command(
				'swift', 'list',
				$newContainer,
				'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
				'-U', 'mw:media',
				'-K', $wmgSwiftPassword
			)->limits( $limits )
				->restrict( Shell::RESTRICT_NONE )
				->execute()->getStdout();

			if ( $newContainerList === $oldContainerList ) {
				// Everything has been correctly copied over
				// wipe files from the temp directory and delete old container

				// Delete the container
				Shell::command(
					'swift', 'delete',
					$container,
					'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
					'-U', 'mw:media',
					'-K', $wmgSwiftPassword
				)->limits( $limits )
					->restrict( Shell::RESTRICT_NONE )
					->execute();

				wfDebugLog( 'MirahezeMagic', "Container '$container' deleted." );

				// Wipe from the temp directory
				Shell::command( '/bin/rm', '-rf', wfTempDir() . '/' . $container )
					->limits( $limits )
					->restrict( Shell::RESTRICT_NONE )
					->execute();
			} else {
				/**
				 * We need to log this, as otherwise all files may not have been succesfully
				 * moved to the new container, and they still exist locally. We should know that.
				 */
				wfDebugLog( 'MirahezeMagic', "The rename of wiki $old to $new may not have been successful. Files still exist locally in {wfTempDir()} and the Swift containers for the old wiki still exist." );
			}
		}

		Shell::makeScriptCommand(
			MW_INSTALL_PATH . '/extensions/CreateWiki/maintenance/setContainersAccess.php',
			[
				'--wiki', $new
			]
		)->limits( $limits )->execute();

		static::removeRedisKey( "*{$old}*" );
		// static::removeMemcachedKey( ".*{$old}.*" );
	}

	public static function onCreateWikiStatePrivate( $dbname ) {
		$localRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		$sitemaps = $localRepo->getBackend()->getTopFileList( [
			'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
			'adviseStat' => false,
		] );

		foreach ( $sitemaps as $sitemap ) {
			$status = $localRepo->getBackend()->quickDelete( [
				'src' => $localRepo->getZonePath( 'public' ) . '/sitemaps/' . $sitemap,
			] );

			if ( !$status->isOK() ) {
				/**
				 * We need to log this, as otherwise the sitemaps may
				 * not be being deleted for private wikis. We should know that.
				 */
				$statusMessage = Status::wrap( $status )->getWikitext();
				wfDebugLog( 'MirahezeMagic', "Sitemap \"{$sitemap}\" failed to delete: {$statusMessage}" );
			}
		}

		$localRepo->getBackend()->clean( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
	}

	public static function onCreateWikiTables( &$tables ) {
		$tables['localnames'] = 'ln_wiki';
		$tables['localuser'] = 'lu_wiki';
	}

	public static function onCreateWikiReadPersistentModel( &$pipeline ) {
		$backend = MediaWikiServices::getInstance()->getFileBackendGroup()->get( 'miraheze-swift' );
		if ( $backend->fileExists( [ 'src' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml' ] ) ) {
			$pipeline = unserialize(
				$backend->getFileContents( [
					'src' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml',
				] )
			);
		}
	}

	public static function onCreateWikiWritePersistentModel( $pipeline ) {
		$backend = MediaWikiServices::getInstance()->getFileBackendGroup()->get( 'miraheze-swift' );
		$backend->prepare( [ 'dir' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) ] );

		$backend->quickCreate( [
			'dst' => $backend->getContainerStoragePath( 'createwiki-persistent-model' ) . '/requestmodel.phpml',
			'content' => $pipeline,
			'overwrite' => true,
		] );

		return true;
	}

	/**
	 * From WikimediaMessages. Allows us to add new messages,
	 * and override ones.
	 *
	 * @param string &$lcKey Key of message to lookup.
	 * @return bool
	 */
	public static function onMessageCacheGet( &$lcKey ) {
		static $keys = [
			'centralauth-groupname',
			'dberr-problems',
			'dberr-again',
			'globalblocking-ipblocked',
			'globalblocking-ipblocked-range',
			'globalblocking-ipblocked-xff',
			'privacypage',
			'prefs-help-realname',
			'newsignuppage-loginform-tos',
			'newsignuppage-must-accept-tos',
			'importtext',
			'importdump-help-reason',
			'importdump-help-target',
			'importdump-help-upload-file',
			'oathauth-step1',
			'centralauth-merge-method-admin-desc',
			'centralauth-merge-method-admin',
			'restriction-protect',
			'restriction-delete',
			'wikibase-sitelinks-miraheze',
			'centralauth-login-error-locked',
			'snapwikiskin',
			'skinname-snapwikiskin',
			'uploadtext',
			'group-checkuser',
			'group-checkuser-member',
			'grouppage-checkuser',
			'group-bureaucrat',
			'grouppage-bureaucrat',
			'group-bureaucrat-member',
			'group-sysop',
			'grouppage-sysop',
			'group-sysop-member',
			'group-interface-admin',
			'grouppage-interface-admin',
			'group-interface-admin-member',
			'group-bot',
			'grouppage-bot',
			'group-bot-member',
			'grouppage-user',
		];

		if ( in_array( $lcKey, $keys, true ) ) {
			$prefixedKey = "miraheze-$lcKey";
			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $lcKey );

			$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );
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
			// Allow link piping, but don't modify $text yet
			$useText = false;
		}

		$target = explode( ':', $target );

		if ( count( $target ) < 2 ) {
			// Not enough parameters for interwiki
			return true;
		}

		if ( $target[0] == '0' ) {
			array_shift( $target );
		}

		$prefix = strtolower( $target[0] );

		if ( $prefix != 'mh' ) {
			// Not interesting
			return true;
		}

		$wiki = strtolower( $target[1] );
		$target = array_slice( $target, 2 );
		$target = implode( ':', $target );

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
		$page = implode( ':', array_slice( $title, 2 ) );
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
			'ResetPassword',
			'Watchlist'
		];

		if ( $user->isAllowed( 'interwiki' ) ) {
			$specialsArray[] = 'Interwiki';
		}

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

	/** Removes redis keys for jobrunner */
	public static function removeRedisKey( string $key ) {
		global $wgJobTypeConf;

		if ( !isset( $wgJobTypeConf['default']['redisServer'] ) || !$wgJobTypeConf['default']['redisServer'] ) {
			return;
		}

		$hostAndPort = IPUtils::splitHostAndPort( $wgJobTypeConf['default']['redisServer'] );

		if ( $hostAndPort ) {
			try {
				$redis = new Redis();
				$redis->connect( $hostAndPort[0], $hostAndPort[1] );
				$redis->auth( $wgJobTypeConf['default']['redisConfig']['password'] );
				$redis->del( $redis->keys( $key ) );
			} catch ( Throwable $ex ) {
				// empty
			}
		}
	}

	/** Remove memcached keys */
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

			$memcached->getDelayed( $keys );

			$store = $memcached->fetchAll();

			$keys = $memcached->getAllKeys();
			foreach ( $keys as $item ) {
				// Decide which keys to delete
				if ( preg_match( "/{$key}/", $item ) ) {
					$memcached->delete( $item );
				} else {
					continue;
				}
			}
		} catch ( Throwable $ex ) {
			// empty
		}
	}

	public static function onMimeMagicInit( $magic ) {
		$magic->addExtraTypes( 'text/plain txt off' );
	}

	public static function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key === 'places' ) {
			$footerItems['termsofservice'] = self::addFooterLink( $skin, 'termsofservice', 'termsofservicepage' );

			$footerItems['donate'] = self::addFooterLink( $skin, 'miraheze-donate', 'miraheze-donatepage' );
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
		$cwConfig = new GlobalVarConfig( 'cw' );

		if ( $cwConfig->get( 'Closed' ) ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed' )->parse() . '</span></div>';
			}
		} elseif ( $cwConfig->get( 'Inactive' ) && $cwConfig->get( 'Inactive' ) !== 'exempt' ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.miraheze.org/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive' )->parse() . '</span></div>';
			}
		}
	}

	/**
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public static function onRecentChange_save( RecentChange $recentChange ) {
 		// phpcs:enable

		if ( $recentChange->mAttribs['rc_type'] !== RC_LOG ) {
			return;
		}

		$globalUserGroups = CentralAuthUser::getInstanceByName( $recentChange->mAttribs['rc_user_text'] )->getGlobalGroups();
		if ( !in_array( 'trustandsafety', $globalUserGroups ) ) {
			return;
		}

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		$data = [
			'writekey' => $config->get( 'MirahezeReportsWriteKey' ),
			'username' => $recentChange->mAttribs['rc_user_text'],
			'log' => $recentChange->mAttribs['rc_log_type'] . '/' . $recentChange->mAttribs['rc_log_action'],
			'wiki' => WikiMap::getCurrentWikiId(),
			'comment' => $recentChange->mAttribs['rc_comment_text'],
		];

		$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
		$httpRequestFactory->post( 'https://reports.miraheze.org/api/ial', [ 'postData' => $data ] );
	}

	public static function onBlockIpComplete( $block, $user, $priorBlock ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'mirahezemagic' );

		// TODO: do we want to add localisation support for these keywords, so they match in other languages as well?
		$blockAlertKeywords = $config->get( 'MirahezeReportsBlockAlertKeywords' );

		foreach ( $blockAlertKeywords as $keyword ) {
			// use strtolower for case insensitivity
			if ( str_contains( strtolower( $block->getReasonComment()->text ), strtolower( $keyword ) ) ) {
				$data = [
					'writekey' => $config->get( 'MirahezeReportsWriteKey' ),
					'username' => $block->getTargetName(),
					'reporter' => $user->getName(),
					'report' => 'people-other',
					'evidence' => 'This is an automatic report. A user was blocked on ' . WikiMap::getCurrentWikiId() . ', and the block matched keyword "' . $keyword . '." The block ID is: ' . $block->getId() . ', and the block reason is: ' . $block->getReasonComment()->text,
				];

				$httpRequestFactory = MediaWikiServices::getInstance()->getHttpRequestFactory();
				$httpRequestFactory->post( 'https://reports.miraheze.org/api/report', [ 'postData' => $data ] );

				break;
			}
		}
	}

	private static function addFooterLink( $skin, $desc, $page ) {
		if ( $skin->msg( $desc )->inContentLanguage()->isDisabled() ) {
			$title = null;
		} else {
			$title = Title::newFromText( $skin->msg( $page )->inContentLanguage()->text() );
		}

		if ( !$title ) {
			return '';
		}

		return Html::element( 'a',
			[ 'href' => $title->fixSpecialName()->getLinkURL() ],
			$skin->msg( $desc )->text()
		);
	}

	public static function onGetPreferences( User $user, array &$preferences ) {
		$preferences['forcesafemode'] = [
			'type' => 'toggle',
			'label-message' => 'prefs-forcesafemode-label',
			'section' => 'rendering',
		];
	}

	public static function onBeforeInitialize( $title, $unused, $output, $user, $request, $mediaWiki ) {
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		if ( $userOptionsLookup->getBoolOption( $user, 'forcesafemode' ) ) {
			$request->setVal( 'safemode', '1' );
		}
	}

	public static function onContributionsToolLinks( $id, Title $title, array &$tools, SpecialPage $specialPage ) {
		$username = $title->getText();
		if ( $specialPage->getUser()->isAllowed( 'centralauth-lock' ) && !IPUtils::isIPAddress( $username ) ) {
			$tools['centralauth'] = $specialPage->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'CentralAuth', $username ),
				strtolower( $specialPage->msg( 'centralauth' )->text() )
			);
		}
	}
}
