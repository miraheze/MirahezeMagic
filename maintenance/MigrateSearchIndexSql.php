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
 * @author Paladox
 * @version 1.0
 */

use Exception;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use MwSql;

class MigrateSearchIndexSql extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Migrates searchindex table sql' );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY );
		$dbname = $this->getConfig()->get( MainConfigNames::DBname );
		if ( $dbname === null ) {
			$this->fatalError( 'Could not identify current database name!' );
		}

		try {
			$table = $dbw->tableName( 'searchindex' );
			$dbw->query( "ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4;", __METHOD__ );
		} catch ( Exception $e ) {
			$this->fatalError( "Failed to alter table 'searchindex'." );
		}

		$mwSql = $this->createChild(
			MwSql::class,
			MW_INSTALL_PATH . '/maintenance/sql.php'
		);

		$mwSql->setArg( 0, MW_INSTALL_PATH . '/maintenance/archives/patch-searchindex-pk-titlelength.sql' );
		$mwSql->execute();
	}
}

// @codeCoverageIgnoreStart
return MigrateSearchIndexSql::class;
// @codeCoverageIgnoreEnd
