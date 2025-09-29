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
 * @ingroup Maintenance
 * @author Agent Isai
 * @version 1.0
 */

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use JobSpecification;
use Wikimedia\Rdbms\IDatabase;

class RequeueForAIEvaluation extends Maintenance {
    public function __construct() {
        parent::__construct();
        $this->addDescription("Loads wiki requests in a defined queue (defaults to 'inreview') for AI evaluation.");

        $this->addArg( 'from', 'Timestamp defining from when to start loading requests.', required: true );
        $this->addOption( 'sleep', 'How many seconds the script should sleep for', required: false, withArg: true );
        $this->addOption( 'queue-name', 'What queue should be processed?', required: false, withArg: true );
    }

    public function execute() {
        $dbw = MediaWikiServices::getInstance()
            ->getDBLoadBalancer()
            ->getConnection( DB_PRIMARY, 'virtual-createwiki-central' );

        $queueName = $this->getOption( 'queue-name', 'inreview' );
        $this->output("Fetching all '$queueName' requests...\n");

        $res = $dbw->newSelectQueryBuilder()
            ->select( 'cw_id' )
            ->from( 'cw_requests' )
            ->where( [
                'cw_status' => $queueName,
                $dbw->expr( 'cw_timestamp', '>', $dbw->timestamp( $this->getArg( 'from' ) ? '20250901000000' ) )
            ] )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        if (!$res->numRows()) {
            $this->output("No requests found with status '$queueName' within the specified range.\n");
            return;
        }

        $jobQueueGroup = MediaWikiServices::getInstance()->getJobQueueGroup();

        foreach ($res as $row) {
            $requestId = (int)$row->cw_id;
            $this->output("Adding wiki request $requestId to queue...\n");

            $job = new JobSpecification(
                'RequestWikiRemoteAIJob',
                [ 'id' => $requestId ]
            );

            $jobQueueGroup->push( $job );
            $this->output("Successfully added wiki request $requestId to the AI evaluation queue!\n");

            $sleepFor = $this->getOption( 'sleep', 30 );
            $this->output("Sleeping for $sleepFor seconds...\n");
            sleep( $sleepFor );
        }

        $this->output("All '$queueName' requests have been queued for processing.\n");
    }
}

// @codeCoverageIgnoreStart
return RequeueForAIEvaluation::class;
// @codeCoverageIgnoreEnd
