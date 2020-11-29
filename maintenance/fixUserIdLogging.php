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
 * @ingroup MirahezeMagic
 * @author Southparkfan
 * @author Universal Omega
 * @version 1.0
 */

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

use Wikimedia\AtEase\AtEase;

class FixUserIdLogging extends Maintenance {
	public $mUserCache;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'fix', 'Actually fix the attribution instead of just checking for wrong entries', false, false );
		$this->mDescription = 'Fixes user attribution in logs';
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$dbr = wfGetDB( DB_REPLICA );

		$start = (int)$dbr->selectField( 'logging', 'MIN(log_id)', false, __METHOD__ );
		$end = (int)$dbr->selectField( 'logging', 'MAX(log_id)', false, __METHOD__ );

		$wrongLogs = 0;

		$lastCheckedLogId = 0;

		do {
			$lastCheckedLogIdNextBatch = $lastCheckedLogId + $this->mBatchSize;
			$logRes = $dbr->select(
				'logging',
				[ 'log_id', 'log_params', 'log_actor' ],
				"log_id BETWEEN $lastCheckedLogId and $lastCheckedLogIdNextBatch",
				__METHOD__
			);

			foreach ( $logRes as $logRow ) {
				$username = User::newFromActorId( $logRow->log_actor );
				$goodUserId = $this->getGoodUserId( $username->getName() );

				// Ignore log_actor 0 for maintenance scripts and such
				if ( $logRow->log_actor != $goodUserId ) {
					// PANIC EVERYWHERE DON'T DIE ON US
					$wrongLogs++;

					if ( $this->hasOption( 'fix' ) ) {
						$this->fixLogEntry( $logRow );
					}
				}
			}

			$lastCheckedLogId = $lastCheckedLogId + $this->mBatchSize;
		} while ( $lastCheckedLogId <= $end );

		$line = "$wrongLogs wrong logs detected.";

		if ( !$this->hasOption( 'fix' ) ) {
			$line .= " Run this script with --fix to actually fix the log entries.";
		}

		$this->output( $line . "\n" );
	}

	public function getGoodUserId( $username ) {
		$whitelist = [ 'Maintenance script', 'MediaWiki default' ];

		if ( in_array( $username, $whitelist ) ) {
			return 0;
		}


		if ( isset( $this->mUserCache[$username] ) ) {
			$goodUserId = $this->mUserCache[$username];
		} else {
			$dbr = wfGetDB( DB_REPLICA );
	
			$userId = $dbr->selectField(
					'user',
				       	'user_id',
					[ 'user_name' => $username ],
					__METHOD__
			);


			if ( ( is_string( $userId ) || is_numeric( $userId ) ) && $userId !== 0 ) {
				$goodUserId = $userId;
			} else {
				$goodUserId = 0;
			}

			$this->mUserCache[$username] = $goodUserId;
		}
		
			return $goodUserId;

	}

	protected function fixLogEntry( $row ) {
		$username = User::newFromActorId( $logRow->log_actor );

		$dbr = wfGetDB( DB_REPLICA );
		$dbw = wfGetDB( DB_MASTER );

		if ( isset( $this->mUserCache[$username->getName()] ) ) {
			$userId = $this->mUserCache[$username->getName()];
		} else {
			$userId = $dbr->selectField(
				'user',
				'user_id',
				[ 'user_name' => $username->getName() ],
				__METHOD__
			);

			$this->mUserCache[$username->getName()] = $userId;
		}

		if ( !( ( is_string( $userId ) || is_numeric( $userId ) ) && $userId !== 0 ) ) {
			$userId = 0;
		}

		AtEase::suppressWarnings();
		$logParams = unserialize( $row->log_params );
		AtEase::restoreWarnings();

		if ( is_array( $logParams ) && isset( $logParams['4::userid'] ) ) {
			$logParams['4::userid'] = $userId;
		}


		$updateParams = [
			'log_actor' => $userId,
		];

		if ( isset( $logParams['4::userid'] ) ) { 
			$logParams = serialize( $logParams );
			$updateParams['log_params'] = $logParams;
		}

		$dbw->update( 'logging',
			$updateParams,
			[ 'log_id' => $row->log_id ],
			__METHOD__
		);

		$this->output( "Done! Updated log_id {$row->log_id} to have the log_actor id {$userId} (for '{$username->getName()}') instead of {$row->log_actor}.\n" );
	}
}

$maintClass = 'FixUserIdLogging';
require_once RUN_MAINTENANCE_IF_MAIN;
