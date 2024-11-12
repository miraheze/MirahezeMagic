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
 * @author Universal Omega
 * @version 1.0
 */

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}

require_once "$IP/maintenance/Maintenance.php";

use Maintenance;
use MediaWiki\Shell\Shell;

class CheckSwiftContainers extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Check for swift containers without matching entries in cw_wikis.' );
		$this->addOption( 'container', 'Check only certain container types.' );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute() {
		// Get the list of swift containers
		$containers = $this->getSwiftContainers();
		if ( !$containers ) {
			$this->fatalError( "No swift containers found.\n" );
		}

		$this->output( 'Found ' . count( $containers ) . " swift containers.\n" );

		// Filter out containers with no corresponding database in cw_wikis
		$missingData = $this->findUnmatchedContainers( $containers );

		if ( $missingData['containers'] ) {
			$this->output( "Containers without matching entries in cw_wikis:\n" );
			foreach ( $missingData['containers'] as $container ) {
				$this->output( " - $container\n" );
			}

			$this->output( "Unique container counts:\n" );
			foreach ( $missingData['uniqueContainerCounts'] as $container => $count ) {
				$this->output( "$container: $count\n" );
			}

			$totalContainersCount = array_sum( $missingData['uniqueContainerCounts'] );
			$this->output( "Total containers count: $totalContainersCount\n" );

			$totalWikiCount = array_sum( $missingData['uniqueWikiCounts'] );
			$this->output( "Total wiki count: $totalWikiCount\n" );
		} else {
			$this->output( "All containers have matching entries in cw_wikis.\n" );
		}
	}

	private function getSwiftContainers() {
		global $wmgSwiftPassword;

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];
		$swiftOutput = Shell::command(
			'swift', 'list',
			'-A', 'https://swift-lb.miraheze.org/auth/v1.0',
			'-U', 'mw:media',
			'-K', $wmgSwiftPassword
		)->limits( $limits )
		->disableSandbox()
		->execute()->getStdout();

		return array_filter( explode( "\n", $swiftOutput ), fn ( $line ) => (bool)$line );
	}

	private function findUnmatchedContainers( array $containers ) {
		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase(
			$this->getConfig()->get( 'CreateWikiDatabase' )
		);

		$suffix = $this->getConfig()->get( 'CreateWikiDatabaseSuffix' );

		$containerOption = $this->getOption( 'container', false );

		$missingContainers = [];
		$uniqueContainerCounts = [];
		$uniqueWikiCounts = [];
		foreach ( $containers as $container ) {
			if ( preg_match( '/^miraheze-([^-]+' . preg_quote( $suffix ) . ')-(.+)$/', $container, $matches ) ) {
				$dbName = $matches[1];
				$containerName = $matches[2];

				if ( $containerOption && $containerName !== $containerOption ) {
					continue;
				}

				// Check if the dbname exists in cw_wikis
				$result = $dbr->newSelectQueryBuilder()
					->select( [ 'wiki_dbname' ] )
					->from( 'cw_wikis' )
					->where( [ 'wiki_dbname' => $dbName ] )
					->caller( __METHOD__ )
					->fetchRow();

				// If no matching wiki is found, add to the missing list
				if ( !$result ) {
					$missingContainers[] = $container;
					if ( !isset( $uniqueContainerCounts[$containerName] ) ) {
						$uniqueContainerCounts[$containerName] = 0;
					}
					$uniqueContainerCounts[$containerName]++;

					if ( !isset( $uniqueWikiCounts[$dbName] ) ) {
						$uniqueWikiCounts[$dbName] = 0;
					}
					$uniqueWikiCounts[$dbName]++;
				}
			}
		}

		return [
			'containers' => $missingContainers,
			'uniqueContainerCounts' => $uniqueContainerCounts,
			'uniqueWikiCounts' => $uniqueWikiCounts,
		];
	}
}

$maintClass = CheckSwiftContainers::class;
require_once RUN_MAINTENANCE_IF_MAIN;
