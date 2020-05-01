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
* @version 2.0
*/

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class RemovePII extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Removes PII information from users (e.g email addresses, ip address and other identifying information).";
		$this->addOption( 'username', 'Username to be used to find requested information.', true, true );
	}

	public function execute() {
		global $wgCentralAuthDatabase;

		$user = User::newFromName( (string)$this->getOption( 'username' ) );
		if ( !$user ) {
			$this->output( 'User does not exist' );
			return;
		}
		
		$userActorId = $user->getActorId( $dbw );
		$userId = $user->getId();

		$dbw = wfGetDB( DB_MASTER );

		$extensionUpdates = [];

		$extensionUpdates = [
			'ajaxpoll_vote' => [
				[
					'fields' => [
						'poll_ip' => '',
					],
					'where' => [
						'poll_actor' => $userActorId,
					]
				]
			],
			'Comments' => [
				[
					'fields' => [
						'Comment_IP' => '',
					],
					'where' => [
						'Comment_actor' => $userActorId,
					]
				]
			],
			'flow_tree_revision' => [
				[
					'fields' => [
						'tree_orig_user_ip' => '',
					],
					'where' => [
						'tree_orig_user_id' => $userId,
					]
				]
			],
			'flow_revision' => [
				[
					'fields' => [
						'rev_user_ip' => '',
					],
					'where' => [
						'rev_user_id' => $userId,
					]
				],
				[
					'fields' => [
						'rev_mod_user_ip' => '',
					],
					'where' => [
						'rev_mod_user_id' => $userId,
					]
				],
				[
					'fields' => [
						'rev_edit_user_ip' => '',
					],
					'where' => [
						'rev_edit_user_id' => $userId,
					]
				]
			],
			'moderation' => [
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '',
					],
					'where' => [
						'mod_user' => $userId,
					]
				]
			],
			'Vote' => [
				[
					'fields' => [
						'vote_ip' => '',
					],
					'where' => [
						'vote_actor' => $userActorId,
					]
				]
			],
			'wikiforum_category' => [
				[
					'fields' => [
						'wfc_added_user_ip' => '',
					],
					'where' => [
						'wfc_added_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wfc_edited_user_ip' => '',
					],
					'where' => [
						'wfc_edited_actor' => $userActorId,
					]
				],

			],
			'wikiforum_forums' => [
				[
					'fields' => [
						'wff_last_post_user_ip' => '',
					],
					'where' => [
						'wff_last_post_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wff_added_user_ip' => '',
					],
					'where' => [
						'wff_added_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wff_edited_user_ip' => '',
					],
					'where' => [
						'wff_edited_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wff_deleted_user_ip' => '',
					],
					'where' => [
						'wff_deleted_actor' => $userActorId,
					]
				],
			],
			'wikiforum_threads' => [
				[
					'fields' => [
						'wft_user_ip' => '',
					],
					'where' => [
						'wft_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wft_deleted_user_ip' => '',
					],
					'where' => [
						'wft_deleted_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wft_edit_user_ip' => '',
					],
					'where' => [
						'wft_edit_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wft_closed_user_ip' => '',
					],
					'where' => [
						'wft_closed_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wft_last_post_user_ip' => '',
					],
					'where' => [
						'wft_last_post_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wft_last_post_user_ip' => '',
					],
					'where' => [
						'wft_last_post_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wfr_user_ip' => '',
					],
					'where' => [
						'wfr_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wfr_deleted_user_ip' => '',
					],
					'where' => [
						'wfr_deleted_actor' => $userActorId,
					]
				],
				[
					'fields' => [
						'wfr_edit_user_ip' => '',
					],
					'where' => [
						'wfr_edit_actor' => $userActorId,
					]
				]
			],

			// Core
			'recentchanges' => [
				[
					'fields' => [
						'rc_ip' => '',
					],
					'where' => [
						'rc_actor' => $userActorId,
					]
				]
			],
			'user' => [
				[
					'fields' => [
						'user_email' => '',
						'user_real_name' => '',
					],
					'where' => [
						'user_name' => $user->getName(),
					]
				]
			]
		];

		if ( $dbw->tableExists( 'user_profile' ) ) {
			$dbw->delete(
				'user_profile',
				[
					'up_actor' => $userActorId
				]
			);
		}

		foreach ( $extensionUpdates as $key => $value ) {
			if ( $dbw->tableExists( $key ) ) {
				foreach ( $value as $name => $fields ) {
					$dbw->update(
						$key,
						$fields['field'],
						$fields['where'],
						__METHOD__
					);
				}
			}
		}

		$dbw = wfGetDB( DB_MASTER, [], $wgCentralAuthDatabase );
		$centralUser = CentralAuthUser::getInstance( $user );
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
	}
}

$maintClass = 'RemovePII';
require_once RUN_MAINTENANCE_IF_MAIN;
