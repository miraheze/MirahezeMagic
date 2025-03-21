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
 * @author Claire Elaina
 * @version 1.0
 */

use MediaWiki\Maintenance\Maintenance;
use MwSql;

class BulkSql extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Run a SQL query or file against a list of wikis, or all wikis' );
		$this->addOption( 'all', 'Run against all wikis (exclusive with --wikis)' );
		$this->addOption( 'wikis', 'Run against all the wikis specified in the file passed ' .
			'(newline separated list of database names) (exclusive with --all)', false, true );

		$this->addOption( 'query', 'Query to run (exclusive with [file])', false, true );
		$this->addArg( 'file', 'SQL file to run (exclusive with --query)', false );
	}

	public function execute() {
		$maint = new MwSql();
		if ( $this->hasOption( 'query' ) && $this->hasArg( 'file' ) ) {
			$this->fatalError( '--query and [file] cannot be specified at the same time' );
		} elseif ( $this->hasArg( 'file' ) ) {
			$maint->setArg( 0, $this->getArg( 'file' ) );
		} elseif ( $this->hasOption( 'query' ) ) {
			$maint->setOption( 'query', $this->getOption( 'query' ) );
		} else {
			$this->fatalError( 'Please pass one of --query or [file]' );
		}

		foreach ( $this->getDatabases() as $dbname ) {
			$this->output( "{$dbname}:\n" );
			$maint->setOption( 'wikidb', $dbname );
			$maint->execute();
		}
	}

	private function getDatabases() {
		if ( $this->hasOption( 'all' ) && $this->hasOption( 'wikis' ) ) {
			$this->fatalError( '--all and --wikis cannot be specified at the same time' );
		} elseif ( $this->hasOption( 'all' ) ) {
			yield from $this->getAllDatabases();
		} elseif ( $this->hasOption( 'wikis' ) ) {
			yield from $this->getDatabasesFromFile( $this->getOption( 'wikis' ) );
		} else {
			$this->fatalError( 'Please pass one of --all or --wikis' );
		}
	}

	private function getAllDatabases() {
		$dbUtils = $this->getServiceContainer()->get( 'CreateWikiDatabaseUtils' );
		$dbr = $dbUtils->getGlobalReplicaDB();
		$rows = $dbr->newSelectQueryBuilder()
			->from( 'cw_wikis' )
			->select( 'wiki_dbname' )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $rows as $row ) {
			yield $row->wiki_dbname;
		}
	}

	private function getDatabasesFromFile( string $filename ) {
		$file = fopen( $filename, 'r' );
		if ( $file === false ) {
			$this->fatalError( 'Failed to open database list' );
		}

		try {
			while ( ( $line = fgets( $file ) ) !== false ) {
				$line = trim( $line );
				if ( $line === '' ) {
					continue;
				}

				yield $line;
			}

			if ( !feof( $file ) ) {
				$this->fatalError( 'Failed to read a line from database list' );
			}
		} finally {
			fclose( $file );
		}
	}

}

// @codeCoverageIgnoreStart
return BulkSql::class;
// @codeCoverageIgnoreEnd
