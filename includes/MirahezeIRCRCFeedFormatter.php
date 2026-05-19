<?php

namespace Miraheze\MirahezeMagic;

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\RCFeed\IRCColourfulRCFeedFormatter;
use MediaWiki\RecentChanges\RecentChange;
use MediaWiki\WikiMap\WikiMap;

class MirahezeIRCRCFeedFormatter extends IRCColourfulRCFeedFormatter {

	/** @inheritDoc */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		$lineFromParent = parent::getLine( $feed, $rc, $actionComment );
		if ( $lineFromParent === null ) {
			return null;
		}

		/**
		 * Don't send renameuser log events to IRC for the
		 * WikiTide Foundation Trust and Safety team.
		 */
		if (
			$rc->getAttribute( 'rc_source' ) === RecentChange::SRC_LOG &&
			$rc->getAttribute( 'rc_log_type' ) === 'renameuser'
		) {
			$globalUserGroups = CentralAuthUser::getInstanceByName( $rc->getAttribute( 'rc_user_text' ) )->getGlobalGroups();
			if ( in_array( 'trustandsafety', $globalUserGroups, true ) ) {
				return null;
			}
		}

		$wikiId = WikiMap::getCurrentWikiId();

		// Prefix is \003, no color (\003) switches
		// back to the term default.
		return "$wikiId \0035*\003 $lineFromParent";
	}
}
