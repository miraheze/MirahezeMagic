<?php

/**
* This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License along
* with this program; if not, write to the Free Software Foundation, Inc.,
* 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
* http://www.gnu.org/copyleft/gpl.html
*
* @file
* @ingroup Maintenance
* @author Miraheze Site Reliability Engeneering team
* @version 2.0
*/

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class RemovePII extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Removes PII information from users (e.g email addresses, ip address and other identifying information).";
		$this->addOption( 'delete', 'Deletes PII info', false, false );
		$this->addOption( 'username', 'Username to be used to find requested information.', true, true );
	}

	public function execute() {
		global $wgCentralAuthDatabase;

		if ( !(bool)$this->getOption( 'delete' ) ) {
			return;
		}

		$user = User::newFromName( (string)$this->getOption( 'username' ) );
		if ( !$user ) {
			$this->output( 'User does not exist' );
			return;
		}

		$dbw = wfGetDB( DB_MASTER );

		if ( class_exists( 'AJAXPoll' ) ) {
			$dbw->update(
				'ajaxpoll_vote',
				[
					'poll_ip' => ''
				],
				[
					'poll_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);
		}

		if ( class_exists( 'Comment' ) ) {
			$dbw->update(
				'Comments',
				[
					'Comment_IP' => ''
				],
				[
					'Comment_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);
		}

		if ( class_exists( 'Flow' ) ) {
			$dbw->update(
				'flow_tree_revision',
				[
					'tree_orig_user_ip' => ''
				],
				[
					'tree_orig_user_id' => $user->getId()
				],
				__METHOD__
			);

			$dbw->update(
				'flow_revision',
				[
					'rev_user_ip' => ''
				],
				[
					'rev_user_id' => $user->getId()
				],
				__METHOD__
			);

			$dbw->update(
				'flow_revision',
				[
					'rev_mod_user_ip' => ''
				],
				[
					'rev_mod_user_id' => $user->getId()
				],
				__METHOD__
			);

			$dbw->update(
				'flow_revision',
				[
					'rev_edit_user_ip' => ''
				],
				[
					'rev_edit_user_id' => $user->getId()
				],
				__METHOD__
			);

			$dbw->update(
				'flow_revision',
				[
					'rev_edit_user_ip' => ''
				],
				[
					'rev_edit_user_id' => $user->getId()
				],
				__METHOD__
			);
		}

		if ( class_exists( 'ModerationAction' ) ) {
			$dbw->update(
				'moderation',
				[
					'mod_header_xff' => '',
					'mod_header_ua' => '',
					'mod_ip' => ''
				],
				[
					'mod_user' => $user->getId()
				],
				__METHOD__
			);
		}

		if ( class_exists( 'SocialProfileHooks' ) ) {
			$dbw->delete(
				'user_profile',
				[
					'up_actor' => $user->getActorId( $dbw )
				]
			);
		}

		if ( class_exists( 'Vote' ) ) {
			$dbw->update(
				'Vote',
				[
					'vote_ip' => ''
				],
				[
					'vote_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);
		}

		if ( class_exists( 'WikiForum' ) ) {
			$dbw->update(
				'wikiforum_category',
				[
					'wfc_added_user_ip' => ''
				],
				[
					'wfc_added_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_category',
				[
					'wfc_edited_user_ip' => ''
				],
				[
					'wfc_edited_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_category',
				[
					'wfc_deleted_user_ip' => ''
				],
				[
					'wfc_deleted_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_forums',
				[
					'wff_last_post_user_ip' => ''
				],
				[
					'wff_last_post_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_forums',
				[
					'wff_added_user_ip' => ''
				],
				[
					'wff_added_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_forums',
				[
					'wff_edited_user_ip' => ''
				],
				[
					'wff_edited_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_forums',
				[
					'wff_deleted_user_ip' => ''
				],
				[
					'wff_deleted_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_threads',
				[
					'wft_user_ip' => ''
				],
				[
					'wft_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_threads',
				[
					'wft_deleted_user_ip' => ''
				],
				[
					'wft_deleted_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_threads',
				[
					'wft_edit_user_ip' => ''
				],
				[
					'wft_edit_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_threads',
				[
					'wft_closed_user_ip' => ''
				],
				[
					'wft_closed_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_threads',
				[
					'wft_last_post_user_ip' => ''
				],
				[
					'wft_last_post_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_replies',
				[
					'wfr_user_ip' => ''
				],
				[
					'wfr_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_replies',
				[
					'wfr_deleted_user_ip' => ''
				],
				[
					'wfr_deleted_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);

			$dbw->update(
				'wikiforum_replies',
				[
					'wfr_edit_user_ip' => ''
				],
				[
					'wfr_edit_actor' => $user->getActorId( $dbw )
				],
				__METHOD__
			);
		}

		$dbw->update(
			'recentchanges',
			[
				'rc_ip' => ''
			],
			[
				'rc_actor' => $user->getActorId( $dbw )
			],
			__METHOD__
		);

		$dbw->update(
			'user',
			[
				'user_email' => '',
				'user_real_name' => '',
			],
			[
				'user_name' => $user->getName()
			],
			__METHOD__
		);

		$dbw = wfGetDB( DB_MASTER, [], $wgCentralAuthDatabase );
		$centralUser = CentralAuthUser::getInstance( $user );
		if ( $centralUser->getEmail() ) {
			$dbw->update(
				'globaluser',
				[
					'gu_email' => '',
				],
				[
					'gu_email' => $centralUser->getEmail(),
					'gu_name' => $centralUser->getName(),
				],
				__METHOD__
			);
		}
	}
}

$maintClass = 'RemovePII';
require_once RUN_MAINTENANCE_IF_MAIN;
