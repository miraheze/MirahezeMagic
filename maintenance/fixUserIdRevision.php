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
* @version 1.0
*/

require_once( __DIR__ . "/Maintenance.php" );

class FixUserIdRevision extends Maintenance {
        public $mUserCache;

        public function __construct() {
                parent::__construct();
                $this->addOption( 'fix', 'Actually fix the attribution instead of just checking for wrong entries', false, false );
                $this->mDescription = 'Fixes user attribution in revisions';
                $this->setBatchSize( 100 );
        }

        public function execute() {
                $dbr = wfGetDB( DB_SLAVE );

                $start = (int)$dbr->selectField( 'revision', 'MIN(rev_id)', false, __METHOD__ );
                $end = (int)$dbr->selectField( 'revision', 'MAX(rev_id)', false, __METHOD__ );

                $wrongRevs = 0;

                $lastCheckedRevId = 0;

                do {
                        $lastCheckedRevIdNextBatch = $lastCheckedRevId + $this->mBatchSize;
                        $revRes = $dbr->select(
                                'revision',
                                array( 'rev_id', 'rev_user_text', 'rev_user' ),
                                "rev_id BETWEEN $lastCheckedRevId and $lastCheckedRevIdNextBatch",
                                __METHOD__
                        );

                        foreach ( $revRes as $revRow ) {
                                $goodUserId = $this->getGoodUserId( $revRow->rev_user_text );

                                // Ignore rev_user 0 for maintenance scripts and such
                                if ( $revRow->rev_user != $goodUserId ) {
                                        // PANIC EVERYWHERE DON'T DIE ON US
                                        $wrongRevs++;

                                        if ( $this->hasOption( 'fix' ) ) {
                                                $this->fixRevEntry( $revRow );
                                        }
                                }
                        }

			$lastCheckedRevId = $lastCheckedRevId + $this->mBatchSize;
                } while ( $lastCheckedRevId <= $end );

                $line = "$wrongRevs wrong revisions detected.";

                if ( !$this->hasOption( 'fix' ) ) {
                        $line .= " Run this script with --fix to actually fix the revisions.";
                }

                $this->output( $line . "\n" );
        }

        public function getGoodUserId( $username ) {
                $whitelist = array( 'Maintenance script', 'MediaWiki default' );

                if ( in_array( $username, $whitelist ) ) {
                        return 0;
                }


                if ( isset( $this->mUserCache[$username] ) ) {
                        $goodUserId = $this->mUserCache[$username];
                } else {
                        $userId = $dbr->selectField(
                                        'user',
                                        'user_id',
                                        array( 'user_name' => $username ),
                                        __METHOD__
                        );


                        if ( ( is_string( $userId ) || is_numeric( $userId ) ) && $userId !== 0 ) {
                                $goodUserId = $userId;
                        } else {
                                $goodUserId = 0;
                        }

                        $this->mUserCache[$username] = $goodUserId;

                        return $goodUserId;
                }
        }

	protected function fixRevEntry( $row ) {
                $dbr = wfGetDB( DB_SLAVE );
                $dbw = wfGetDB( DB_MASTER );

                if ( isset( $this->mUserCache[$row->rev_user_text] ) ) {
                        $userId = $this->mUserCache[$row->rev_user_text];
                } else {
                        $userId = $dbr->selectField(
                                'user',
                                'user_id',
                                array( 'user_name' => $row->rev_user_text ),
                                __METHOD__
                        );

                        $this->mUserCache[$row->rev_user_text] = $userId;
                }

                if ( ( is_string( $userId ) || is_numeric( $userId ) ) && $userId !== 0 ) {
                        $userId = $userId;
                } else {
                        $userId = 0;
                }


                $updateParams = array(
                        'rev_user' => $userId,
                );

                $dbw->update( 'revision',
                        $updateParams,
                        array( 'rev_id' => $row->rev_id ),
                        __METHOD__
                );

                $this->output( "Done! Updated rev_id {$row->rev_id} to have the rev_user id {$userId} (for '{$row->rev_user_text}') instead of {$row->rev_user}.\n" );
        }
}

$maintClass = 'FixUserIdRevision';
require_once RUN_MAINTENANCE_IF_MAIN;
