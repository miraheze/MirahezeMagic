<?php

namespace Miraheze\MirahezeMagic;

use IRCColourfulRCFeedFormatter;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use RecentChange;

class MirahezeIRCRCFeedFormatter implements IRCColourfulRCFeedFormatter {

	/** @inheritDoc */
	public function getLine( array $feed, RecentChange $rc, $actionComment ) {
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

		# see http://www.irssi.org/documentation/formats for some colour codes. prefix is \003,
		# no colour (\003) switches back to the term default
		return "$dbname \0035*\003 " .
			parent::getLine( $feed, $rc, $actionComment );
	}
}
