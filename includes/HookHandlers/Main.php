<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Api\ApiQuerySiteinfo;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\Cache\Hook\MessageCacheFetchOverridesHook;
use MediaWiki\Config\Config;
use MediaWiki\Config\GlobalVarConfig;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Hook\BlockIpCompleteHook;
use MediaWiki\Hook\GetLocalURL__InternalHook;
use MediaWiki\Hook\MimeMagicInitHook;
use MediaWiki\Hook\RecentChange_saveHook;
use MediaWiki\Hook\SiteNoticeAfterHook;
use MediaWiki\Hook\SkinAddFooterLinksHook;
use MediaWiki\Html\Html;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Permissions\Hook\TitleReadWhitelistHook;
use MediaWiki\Permissions\Hook\UserGetRightsRemoveHook;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use MessageCache;
use RecentChange;
use Skin;

class Main implements
	APIQuerySiteInfoGeneralInfoHook,
	BlockIpCompleteHook,
	GetLocalURL__InternalHook,
	MessageCacheFetchOverridesHook,
	MimeMagicInitHook,
	RecentChange_saveHook,
	SiteNoticeAfterHook,
	SkinAddFooterLinksHook,
	TitleReadWhitelistHook,
	UserGetRightsRemoveHook
{

	public function __construct(
		private readonly HttpRequestFactory $httpRequestFactory,
		private readonly ServiceOptions $options
	) {
	}

	public static function factory(
		Config $mainConfig,
		HttpRequestFactory $httpRequestFactory
	): self {
		return new self(
			$httpRequestFactory,
			new ServiceOptions(
				[
					'MirahezeMagicAccessIdsMap',
					'MirahezeReportsBlockAlertKeywords',
					'MirahezeReportsWriteKey',
					MainConfigNames::ArticlePath,
					MainConfigNames::LanguageCode,
					MainConfigNames::Script,
				],
				$mainConfig
			)
		);
	}

	/**
	 * @inheritDoc
	 * @param ApiQuerySiteinfo $module @phan-unused-param
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $module, &$result ) {
		$result['miraheze'] = true;
	}

	/** @inheritDoc */
	public function onMessageCacheFetchOverrides( array &$keys ): void {
		static $keysToOverride = [
			'centralauth-groupname',
			'centralauth-login-error-locked',
			'createwiki-close-email-body',
			'createwiki-close-email-sender',
			'createwiki-close-email-subject',
			'createwiki-defaultmainpage',
			'createwiki-defaultmainpage-summary',
			'createwiki-email-body',
			'createwiki-email-subject',
			'createwiki-error-subdomaintaken',
			'createwiki-help-bio',
			'createwiki-help-category',
			'createwiki-help-reason',
			'createwiki-help-subdomain',
			'createwiki-label-reason',
			'dberr-again',
			'dberr-problems',
			'globalblocking-blockedtext-blocker-admin',
			'globalblocking-ipblocked-range',
			'globalblocking-ipblocked-xff',
			'globalblocking-ipblocked',
			'grouppage-autoconfirmed',
			'grouppage-automoderated',
			'grouppage-autoreview',
			'grouppage-blockedfromchat',
			'grouppage-bot',
			'grouppage-bureaucrat',
			'grouppage-chatmod',
			'grouppage-checkuser',
			'grouppage-commentadmin',
			'grouppage-csmoderator',
			'grouppage-editor',
			'grouppage-flow-bot',
			'grouppage-interface-admin',
			'grouppage-moderator',
			'grouppage-no-ipinfo',
			'grouppage-reviewer',
			'grouppage-suppress',
			'grouppage-sysop',
			'grouppage-upwizcampeditors',
			'grouppage-user',
			'importdump-help-reason',
			'importdump-help-target',
			'importdump-help-upload-file',
			'importdump-import-failed-comment',
			'importtext',
			'interwiki_intro',
			'newsignuppage-loginform-tos',
			'newsignuppage-must-accept-tos',
			'oathauth-step1',
			'prefs-help-realname',
			'privacypage',
			'requestcustomdomain-help-target-subdomain',
			'requestwiki-error-invalidcomment',
			'requestwiki-info-guidance',
			'requestwiki-info-guidance-post',
			'requestwiki-label-agreement',
			'requestwiki-success',
			'restriction-delete',
			'restriction-protect',
			'skinname-snapwikiskin',
			'snapwikiskin',
			'uploadtext',
			'vector-night-mode-issue-reporting-notice-url',
			'webauthn-module-description',
			'wikibase-sitelinks-miraheze',
		];

		$languageCode = $this->options->get( MainConfigNames::LanguageCode );
		$transformationCallback = static function ( string $key, MessageCache $cache ) use ( $languageCode ): string {
			$transformedKey = "miraheze-$key";

			// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
			// of the above.  Revisit if non-ASCII keys are used.
			$ucKey = ucfirst( $key );

			if (
				/**
				 * Override order:
				 * 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
				 * (in all languages) with normal fallback order.  Specific
				 * language pages (MediaWiki:$ucKey/xy) are not checked when
				 * deciding which key to use, but are still used if applicable
				 * after the key is decided.
				 *
				 * 2. Otherwise, use the prefixed key with normal fallback order
				 * (including MediaWiki pages if they exist).
				 */
				$cache->getMsgFromNamespace( $ucKey, $languageCode ) === false
			) {
				return $transformedKey;
			}

			return $key;
		};

		foreach ( $keysToOverride as $key ) {
			$keys[$key] = $transformationCallback;
		}
	}

	/**
	 * @inheritDoc
	 * @param User $user @phan-unused-param
	 */
	public function onTitleReadWhitelist( $title, $user, &$whitelisted ) {
		if ( $title->isMainPage() ) {
			$whitelisted = true;
			return;
		}

		$allowedSpecialPages = [
			'CentralAutoLogin',
			'CentralLogin',
			'ChangePassword',
			'ConfirmEmail',
			'CreateAccount',
			'Notifications',
			'OAuth',
			'PasswordReset',
			'Userlogin',
		];

		if ( $title->isSpecialPage() ) {
			foreach ( $allowedSpecialPages as $name ) {
				if ( $title->isSpecial( $name ) ) {
					$whitelisted = true;
					return;
				}
			}
		}
	}

	/** @inheritDoc */
	public function onMimeMagicInit( $mimeMagic ) {
		$mimeMagic->addExtraTypes( 'text/plain txt off' );
	}

	/** @inheritDoc */
	public function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerItems ) {
		if ( $key === 'places' ) {
			$footerItems['termsofservice'] = $this->addFooterLink( $skin, 'termsofservice', 'termsofservicepage' );
			$footerItems['donate'] = $this->addFooterLink( $skin, 'miraheze-donate', 'miraheze-donatepage' );
		}
	}

	/** @inheritDoc */
	public function onUserGetRightsRemove( $user, &$rights ) {
		// Remove read from global groups on some wikis
		foreach ( $this->options->get( 'MirahezeMagicAccessIdsMap' ) as $wiki => $ids ) {
			if ( WikiMap::isCurrentWikiId( $wiki ) && $user->isRegistered() ) {
				$centralAuthUser = CentralAuthUser::getInstance( $user );

				if ( $centralAuthUser &&
					$centralAuthUser->exists() &&
					!in_array( $centralAuthUser->getId(), $ids, true )
				) {
					$rights = array_unique( $rights );
					unset( $rights[array_search( 'read', $rights, true )] );
				}
			}
		}
	}

	/** @inheritDoc */
	public function onSiteNoticeAfter( &$siteNotice, $skin ) {
		$cwConfig = new GlobalVarConfig( 'cw' );
		if ( $cwConfig->get( 'Closed' ) ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.net/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-private' )->parse() . '</span></div>';
			} elseif ( $cwConfig->get( 'Locked' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.net/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed-locked' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.net/metawiki/0/02/Wiki_lock.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-closed' )->parse() . '</span></div>';
			}
		} elseif ( $cwConfig->get( 'Inactive' ) && $cwConfig->get( 'Inactive' ) !== 'exempt' ) {
			if ( $cwConfig->get( 'Private' ) ) {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.net/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive-private' )->parse() . '</span></div>';
			} else {
				$siteNotice .= '<div class="wikitable" style="text-align: center; width: 90%; margin-left: auto; margin-right:auto; padding: 15px; border: 4px solid black; background-color: #EEE;"> <span class="plainlinks"> <img src="https://static.wikitide.net/metawiki/5/5f/Out_of_date_clock_icon.png" align="left" style="width:80px;height:90px;">' . $skin->msg( 'miraheze-sitenotice-inactive' )->parse() . '</span></div>';
			}
		}
	}

	/**
	 * @inheritDoc
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public function onRecentChange_save( $recentChange ) {
 		// phpcs:enable

		if ( $recentChange->getAttribute( 'rc_source' ) !== RecentChange::SRC_LOG ) {
			return;
		}

		$globalUserGroups = CentralAuthUser::getInstanceByName( $recentChange->getAttribute( 'rc_user_text' ) )->getGlobalGroups();
		if ( !in_array( 'trustandsafety', $globalUserGroups, true ) ) {
			return;
		}

		$data = [
			'writekey' => $this->options->get( 'MirahezeReportsWriteKey' ),
			'username' => $recentChange->getAttribute( 'rc_user_text' ),
			'log' => $recentChange->getAttribute( 'rc_log_type' ) . '/' . $recentChange->getAttribute( 'rc_log_action' ),
			'wiki' => WikiMap::getCurrentWikiId(),
			'comment' => $recentChange->getAttribute( 'rc_comment' ),
		];

		$this->httpRequestFactory->post( 'https://reports.miraheze.org/api/ial', [ 'postData' => $data ], __METHOD__ );
	}

	/**
	 * @inheritDoc
	 * @param ?DatabaseBlock $priorBlock @phan-unused-param
	 */
	public function onBlockIpComplete( $block, $user, $priorBlock ) {
		// TODO: do we want to add localization support for these keywords, so they match in other languages as well?
		$blockAlertKeywords = $this->options->get( 'MirahezeReportsBlockAlertKeywords' );
		foreach ( $blockAlertKeywords as $keyword ) {
			// use mb_strtolower for case insensitivity
			if ( str_contains( mb_strtolower( $block->getReasonComment()->text ), mb_strtolower( $keyword ) ) ) {
				$data = [
					'writekey' => $this->options->get( 'MirahezeReportsWriteKey' ),
					'username' => $block->getTargetName(),
					'reporter' => $user->getName(),
					'report' => 'people-other',
					'evidence' => 'This is an automatic report. A user was blocked on ' . WikiMap::getCurrentWikiId() . ', and the block matched keyword "' . $keyword . '." The block ID is: ' . $block->getId() . ', and the block reason is: ' . $block->getReasonComment()->text,
				];

				$this->httpRequestFactory->post( 'https://reports.miraheze.org/api/report', [ 'postData' => $data ], __METHOD__ );
				return;
			}
		}
	}

	/**
	 * @inheritDoc
	 * phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
	 */
	public function onGetLocalURL__Internal( $title, &$url, $query ) {
		// phpcs:enable

		if ( defined( 'MW_PHPUNIT_TEST' ) ) {
			return;
		}

		// If the URL contains wgScript, rewrite it to use wgArticlePath
		if ( str_contains( $url, $this->options->get( MainConfigNames::Script ) ) ) {
			$dbkey = wfUrlencode( $title->getPrefixedDBkey() );
			$url = str_replace( '$1', $dbkey, $this->options->get( MainConfigNames::ArticlePath ) );
			if ( $query !== '' ) {
				$url = wfAppendQuery( $url, $query );
			}
		}
	}

	private function addFooterLink( Skin $skin, string $desc, string $page ): string {
		if ( $skin->msg( $desc )->inContentLanguage()->isDisabled() ) {
			return '';
		}

		$url = Skin::makeInternalOrExternalUrl(
			$skin->msg( $page )->inContentLanguage()->text()
		);

		return Html::element( 'a',
			[ 'href' => $url ],
			$skin->msg( $desc )->text()
		);
	}
}
