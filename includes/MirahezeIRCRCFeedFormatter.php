<?php

namespace Miraheze\MirahezeMagic;

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\RCFeed\IRCColourfulRCFeedFormatter;
use MediaWiki\WikiMap\WikiMap;
use RecentChange;

class MirahezeIRCRCFeedFormatter extends IRCColourfulRCFeedFormatter {

	/** @inheritDoc */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		$lineFromParent = parent::getLine( $feed, $rc, $actionComment );
		if ( $lineFromParent === null ) {
			return null;
		}

		$wikiId = WikiMap::getCurrentWikiId();
		$attribs = $rc->getAttributes();

		/**
		 * Don't send renameuser log events to IRC for the
		 * WikiTide Foundation Trust and Safety team.
		 */
		if (
			$attribs['rc_source'] === RecentChange::SRC_LOG &&
			$attribs['rc_log_type'] === 'renameuser'
		) {
			$globalUserGroups = CentralAuthUser::getInstanceByName( $attribs['rc_user_text'] )->getGlobalGroups();
			if ( in_array( 'trustandsafety', $globalUserGroups, true ) ) {
				return null;
			}
		}

		// Prefix is \003, no colour (\003) switches
		// back to the term default.
		return "$wikiId \0035*\003 $lineFromParent";
	}
}
