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
* @author Miraheze Operations
* @version 1.0
*/

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class PIIRemoval extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Removes PII information from users (e.g email addresses, ip's and other identifying information). Username changes must be merged into GDPRAccount user.";
		$this->addOption( 'delete', 'Deletes PII info (e.g emails, ip\'s, user agents)', false, false );
		$this->addOption( 'username', 'Username to be used to find requested information.', true, true );
	}

	public function execute() {
		global $wgCentralAuthDatabase;

		$user = User::newFromName( $this->getOption( 'username' ) );
		$centralUser = CentralAuthUser::getInstance( $user );
		if ( $centralUser->exists() ) {
			$this->output( "CentralAuth Results:\n" );

			$this->output( "  Username: {$centralUser->getName()}\n" );

			$this->output( "  Home Wiki: {$centralUser->getHomeWiki()}\n" );

			$locked = ( $centralUser->isLocked() ) ? 'True' : 'False';
			$this->output( "  Account Locked: " . $locked . "\n" );

			$this->output( "  Attached Wikis: " . implode( ',', $centralUser->listAttached() ) . "\n" );

			$this->countDown( 10 );

			$db = wfGetDB( DB_MASTER, [], $wgCentralAuthDatabase );
			if ( $this->getOption( 'delete' ) ) {
				if ( $this->getOption( 'email' ) && $centralUser->getEmail() ) {
					$db->update(
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

			foreach ( $centralUser->listAttached() as $wikiName ) {
				if ( $this->getOption( 'delete' ) ) {
					$db = $db->selectDomain( $wikiName );
					if ( $centralUser->getEmail() ) {
						$db->update(
							'user',
							[
								'user_email' => ''
							],
							[
								'user_email' => $centralUser->getEmail(),
								'user_name' => $centralUser->getName()
							],
							__METHOD__
						);
					}

					$db->update(
						'cu_changes',
						[
							'cuc_agent' => '',
							'cuc_xff_hex' => '',
							'cuc_xff' => '127.0.0.1',
							'cuc_ip_hex' => '',
							'cuc_ip' => '0.0.0.0'
						],
						[
							'cuc_user_text' => $centralUser->getName()
						],
						__METHOD__
					);

					$db->update(
						'recentchanges',
						[
							'rc_ip' => '0.0.0.0'
						],
						[
							'rc_actor' => $user->getActorId( $db )
						],
						__METHOD__
					);
				}
			}
		}
	}
}

$maintClass = 'PIIRemoval';
require_once RUN_MAINTENANCE_IF_MAIN;
