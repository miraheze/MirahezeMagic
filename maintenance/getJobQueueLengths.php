<?php

/**
 * Get the length of the job queue on all wikis in $wgConf
 * Sourced from WikimediaMaintenance
 */

require_once( '/srv/mediawiki/w/maintenance/Maintenance.php' );

use MediaWiki\MediaWikiServices;

class GetJobQueueLengths extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = 'Get the length of the job queue on all wikis in $wgConf';
		$this->addOption( 'totalonly', 'Whether to only output the total number of jobs' );
		$this->addOption( 'nototal', "Don't print the total number of jobs" );
		$this->addOption( 'grouponly', "Show a per-wiki/per-type count map in JSON" );
		$this->addOption( 'report', "Report the total count of job queue items to StatsD" );
	}

	function execute() {
		if ( $this->hasOption( 'grouponly' ) && $this->hasOption( 'report' ) ) {
			$this->fatalError( "You cannot specify both '--report' and '--grouponly'." );
		}

		$totalOnly = $this->hasOption( 'totalonly' );

		$pendingDBs = JobQueueAggregator::singleton()->getAllReadyWikiQueues();
		$sizeByWiki = []; // (wiki => type => count) map
		foreach ( $pendingDBs as $type => $wikis ) {
			foreach ( $wikis as $wiki ) {
				$sizeByWiki[$wiki][$type] =
					JobQueueGroup::singleton( $wiki )->get( $type )->getSize();
			}
		}

		uasort(
			$sizeByWiki,
			function ( $typeTotalsA, $typeTotalsB ) {
				$sumA = array_sum( $typeTotalsA );
				$sumB = array_sum( $typeTotalsB );
				if ( $sumA == $sumB ) {
					return 0;
				}

				return ( $sumA > $sumB ) ? -1 : 1;
			}
		);

		if ( $this->hasOption( 'grouponly' ) ) {
			$this->output( FormatJson::encode( $sizeByWiki, true ) . "\n" );
		} else {
			$total = 0;
			foreach ( $sizeByWiki as $wiki => $counts ) {
				$count = array_sum( $counts );
				if ( $count > 0 ) {
					if ( !$totalOnly ) {
						$this->output( "$wiki $count\n" );
					}
					$total += $count;
				}
			}
			if ( !$this->hasOption( 'nototal' ) ) {
				$this->output( "Total $total\n" );
			}

			if ( $this->hasOption( 'report' ) ) {
				MediaWikiServices::getInstance()->getStatsdDataFactory()
					->gauge( 'jobqueue.size', $total );
			}
		}
	}
}

$maintClass = 'GetJobQueueLengths';
require_once RUN_MAINTENANCE_IF_MAIN;
