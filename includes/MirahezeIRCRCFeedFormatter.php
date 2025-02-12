<?php

namespace Miraheze\MirahezeMagic;

use MediaWiki\RCFeed\IRCColourfulRCFeedFormatter;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use RecentChange;

class MirahezeIRCRCFeedFormatter extends IRCColourfulRCFeedFormatter {

	/** @inheritDoc */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
		$lineFromParent = parent::getLine( $feed, $rc, $actionComment );
		if ( $lineFromParent === null ) {
			return null;
		}

		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$dbname = $mainConfig->get( MainConfigNames::DBname );

		$attribs = $rc->getAttributes();

		/**
		 * Don't send renameuser log events to IRC for the
		 * WikiTide Foundation Trust and Safety team.
		 */
		if (
			$attribs['rc_type'] == RC_LOG &&
			$attribs['rc_log_type'] === 'renameuser'
		) {
			$globalUserGroups = CentralAuthUser::getInstanceByName( $attribs['rc_user_text'] )->getGlobalGroups();
			if ( in_array( 'trustandsafety', $globalUserGroups ) ) {
				return null;
			}
		}

		// Prefix is \003, no colour (\003) switches
		// back to the term default.
		return "$dbname \0035*\003 $lineFromParent";
	}
}
