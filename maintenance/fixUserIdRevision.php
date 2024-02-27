<?php

namespace Miraheze\MirahezeMagic\Maintenance;

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
 * @version 1.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;

class FixUserIdRevision extends Maintenance {

	/** @var array */
	private $userCache;

	public function __construct() {
		parent::__construct();
		$this->addOption( 'fix', 'Actually fix the attribution instead of just checking for wrong entries', false, false );

		$this->addDescription( 'Fixes user attribution in revisions' );
		$this->setBatchSize( 100 );
	}

	public function execute() {
		$dbr = $this->getDB( DB_REPLICA );

		$start = (int)$dbr->selectField( 'revision', 'MIN(rev_id)', [], __METHOD__ );
		$end = (int)$dbr->selectField( 'revision', 'MAX(rev_id)', [], __METHOD__ );

		$wrongRevs = 0;

		$lastCheckedRevId = 0;

		do {
			$lastCheckedRevIdNextBatch = $lastCheckedRevId + $this->getBatchSize();
			$revRes = $dbr->select(
				'revision',
				[ 'rev_id', 'rev_actor' ],
				"rev_id BETWEEN $lastCheckedRevId and $lastCheckedRevIdNextBatch",
				__METHOD__
			);

			foreach ( $revRes as $revRow ) {
				$revActor = $this->getServiceContainer()->getUserFactory()->newFromActorId( $revRow->rev_actor );
				$goodUserId = $this->getGoodUserId( $revActor->getName() );

				// Ignore rev_actor 0 for maintenance scripts and such
				if ( $revRow->rev_actor != $goodUserId ) {
					// PANIC EVERYWHERE DON'T DIE ON US
					$wrongRevs++;

					if ( $this->hasOption( 'fix' ) ) {
						$this->fixRevEntry( $revRow );
					}
				}
			}

			$lastCheckedRevId += $this->getBatchSize();
		} while ( $lastCheckedRevId <= $end );

		$line = "$wrongRevs wrong revisions detected.";

		if ( !$this->hasOption( 'fix' ) ) {
			$line .= ' Run this script with --fix to actually fix the revisions.';
		}

		$this->output( $line . "\n" );
	}

	public function getGoodUserId( $username ) {
		$allowed = [ 'Maintenance script', 'MediaWiki default' ];

		if ( in_array( $username, $allowed ) ) {
			return 0;
		}

		if ( isset( $this->userCache[$username] ) ) {
			$goodUserId = $this->userCache[$username];
		} else {
			$dbr = $this->getDB( DB_REPLICA );

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

			$this->userCache[$username] = $goodUserId;
		}

		return $goodUserId;
	}

	protected function fixRevEntry( $row ) {
		$dbr = $this->getDB( DB_REPLICA );
		$dbw = $this->getDB( DB_PRIMARY );
		$revActor = $this->getServiceContainer()->getUserFactory()->newFromActorId( $row->rev_actor );

		if ( isset( $this->userCache[$revActor->getName()] ) ) {
			$userId = $this->userCache[$revActor->getName()];
		} else {
			$userId = $dbr->selectField(
				'user',
				'user_id',
				[ 'user_name' => $revActor->getName() ],
				__METHOD__
			);

			$this->userCache[$revActor->getName()] = $userId;
		}

		if ( !( ( is_string( $userId ) || is_numeric( $userId ) ) && $userId !== 0 ) ) {
			$userId = 0;
		}

		$updateParams = [
			'rev_actor' => $userId,
		];

		$dbw->update( 'revision',
			$updateParams,
			[ 'rev_id' => $row->rev_id ],
			__METHOD__
		);

		$this->output( "Done! Updated rev_id {$row->rev_id} to have the rev_actor id {$userId} (for '{$revActor->getName()}') instead of {$row->rev_actor}.\n" );
	}
}

$maintClass = FixUserIdRevision::class;
require_once RUN_MAINTENANCE_IF_MAIN;
