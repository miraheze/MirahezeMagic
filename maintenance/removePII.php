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
* @author Miraheze Site Reliability Engineering team
* @version 2.0.1
*/

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use MediaWiki\MediaWikiServices;

class RemovePII extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Removes PII information from users (e.g email addresses, ip address and other identifying information).";
		$this->addOption( 'oldname', 'Old name', false, true );
		$this->addOption( 'newname', 'New name', false, true );
		$this->addOption( 'only-generate-username', 'Generates random username', false, false );
	}

	public function execute() {
		global $wgCentralAuthDatabase;

		if ( $this->getOption( 'only-generate-username' ) ) {
			$username = 'MirahezeGDPR_' . substr(sha1(random_bytes(10)), 0, 32);
			$this->output( "New username: {$username}\n" );
			return;
		}

		$oldName = (string)$this->getOption( 'oldname' );
		$newName = (string)$this->getOption( 'newname' );
		if ( !$oldName || !$newName ) {
			$this->output( "You must supply both --oldname and --newname\n" );
			return;
		}

		$oldName = User::newFromName( $oldName );
		$newName = User::newFromName( $newName );
		$userOldName = $oldName->getName();
		$userNewName = $newName->getName();

		if ( !$newName ) {
			$this->output( "User {$userNewName} is not a valid name\n" );
			return;
		}

		$userId = $newName->getId();
		if ( !$userId ) {
			$this->output( "User {$userNewName} id equals 0\n" );
			return;
		}

		$dbw = wfGetDB( DB_PRIMARY );

		$userActorId = $newName->getActorId( $dbw );

		$tableUpdates = [

			// Core
			'recentchanges' => [
				[
					'fields' => [
						'rc_ip' => '0.0.0.0'
					],
					'where' => [
						'rc_actor' => $userActorId
					]
				]
			],
			'user' => [
				[
					'fields' => [
						'user_email' => '',
						'user_real_name' => ''
					],
					'where' => [
						'user_name' => $userNewName
					]
				]
			],

			// Extensions
			'abuse_filter_log' => [
				[
					'fields' => [
						'afl_user_text' => $userNewName
					],
					'where' => [
						'afl_user_text' => $userOldName
					]
				]
			],
			'ajaxpoll_vote' => [
				[
					'fields' => [
						'poll_ip' => '0.0.0.0'
					],
					'where' => [
						'poll_actor' => $userActorId
					]
				]
			],
			'Comments' => [
				[
					'fields' => [
						'Comment_IP' => '0.0.0.0'
					],
					'where' => [
						'Comment_actor' => $userActorId
					]
				],
			],
			'echo_event' => [
				[
					'fields' => [
						'event_agent_ip' => NULL
					],
					'where' => [
						'event_agent_id' => $userId
					]
				]
			],
			'flow_tree_revision' => [
				[
					'fields' => [
						'tree_orig_user_ip' => NULL
					],
					'where' => [
						'tree_orig_user_id' => $userId
					]
				]
			],
			'flow_revision' => [
				[
					'fields' => [
						'rev_user_ip' => NULL
					],
					'where' => [
						'rev_user_id' => $userId
					]
				],
				[
					'fields' => [
						'rev_mod_user_ip' => NULL
					],
					'where' => [
						'rev_mod_user_id' => $userId
					]
				],
				[
					'fields' => [
						'rev_edit_user_ip' => NULL
					],
					'where' => [
						'rev_edit_user_id' => $userId
					]
				]
			],
			'moderation' => [
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0'
					],
					'where' => [
						'mod_user' => $userId
					]
				],
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0',
						'mod_user_text' => $userNewName
					],
					'where' => [
						'mod_user_text' => $userOldName
					]
				]
			],
			'report_reports' => [
				[
					'fields' => [
						'report_user_text' => $userNewName
					],
					'where' => [
						'report_user_text' => $userOldName
					]
				],
				[
					'fields' => [
						'report_handled_by_text' => $userNewName
					],
					'where' => [
						'report_handled_by_text' => $userOldName
					]
				]
			],
			'Vote' => [
				[
					'fields' => [
						'vote_ip' => '0.0.0.0',
					],
					'where' => [
						'vote_actor' => $userActorId
					]
				]
			],
			'wikiforum_category' => [
				[
					'fields' => [
						'wfc_added_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfc_added_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wfc_edited_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfc_edited_actor' => $userActorId
					]
				],
			],
			'wikiforum_forums' => [
				[
					'fields' => [
						'wff_last_post_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wff_last_post_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wff_added_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wff_added_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wff_edited_user_ip' => '0.0.0.0'
					],
					'where' => [
						'wff_edited_actor' => $userActorId
					]
				],
			],
			'wikiforum_replies' => [
				[
					'fields' => [
						'wfr_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfr_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wfr_edit_user_ip' => '0.0.0.0',
					],
					'where' => [
						'wfr_edit_actor' => $userActorId
					]
				],
			],
			'wikiforum_threads' => [
				[
					'fields' => [
						'wft_user_ip' => '0.0.0.0'
					],
					'where' => [
						'wft_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wft_edit_user_ip' => '0.0.0.0'
					],
					'where' => [
						'wft_edit_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wft_closed_user_ip' => '0.0.0.0'
					],
					'where' => [
						'wft_closed_actor' => $userActorId
					]
				],
				[
					'fields' => [
						'wft_last_post_user_ip' => '0.0.0.0'
					],
					'where' => [
						'wft_last_post_actor' => $userActorId
					]
				]
			],
		];

		if ( $dbw->tableExists( 'user_profile' ) ) {
			$dbw->delete(
				'user_profile',
				[
					'up_actor' => $userActorId
				]
			);
		}

		foreach ( $tableUpdates as $key => $value ) {
			if ( $dbw->tableExists( $key ) ) {
				foreach ( $value as $name => $fields ) {
					try {
						$dbw->update(
							$key,
							$fields['fields'],
							$fields['where'],
							__METHOD__
						);
					} catch( Exception $ex ) {
						$this->output( "Table {$key} either does not exist or the update failed.\n" );
					}
				}
			}
		}

		$logTitle = Title::newFromText( 'Special:CentralAuth' )->getSubpage( $userNewName );
		$dbw->delete(
			'logging',
			[
				'log_action' => 'rename',
				'log_title' => $logTitle->getDBkey(),
				'log_type' => 'gblrename'
			]
		);

		$dbw->delete(
			'logging',
			[
				'log_action' => 'renameuser',
				'log_title' => $oldName->getTitleKey(),
				'log_type' => 'renameuser'
			]
		);

		$dbw->delete(
			'recentchanges',
			[
				'rc_log_action' => 'rename',
				'rc_title' => $logTitle->getDBkey(),
				'rc_log_type' => 'gblrename'
			]
		);

		$dbw->delete(
			'recentchanges',
			[
				'rc_log_action' => 'renameuser',
				'rc_title' => $oldName->getTitleKey(),
				'rc_log_type' => 'renameuser'
			]
		);

		$user = User::newSystemUser( 'MediaWiki default', [ 'steal' => true ] );

		if ( !$user ) {
			$this->fatalError( "Invalid username" );
		}

		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();

		global $wgUser;
		$wgUser = $user;

		// Hide deletions from RecentChanges
		$userGroupManager->addUserToGroup( $user, 'bot', null, true );

		$error = '';
		$title = Title::newFromText( $oldName->getTitleKey(), NS_USER );
		$userPage = WikiPage::factory( $title );
		$status = $userPage->doDeleteArticleReal( '', $user );

		if ( !$status->isOK() ) {
			$errorMessage = json_encode( $status->getErrorsByType( 'error' ) );
			$this->output( "Failed to delete user {$userOldName} page, likely does not have a user page. Error: {$errorMessage}\n" );
		}

		$dbw = wfGetDB( DB_PRIMARY, [], $wgCentralAuthDatabase );
		$centralUser = CentralAuthUser::getInstance( $newName );

		if ( !$centralUser ) {
			return;
		}

		if ( $centralUser->getEmail() ) {
			$dbw->update(
				'globaluser',
				[
					'gu_email' => ''
				],
				[
					'gu_email' => $centralUser->getEmail(),
					'gu_name' => $centralUser->getName()
				],
				__METHOD__
			);
		}

		$centralUser->adminLock();
	}
}

$maintClass = 'RemovePII';
require_once RUN_MAINTENANCE_IF_MAIN;
