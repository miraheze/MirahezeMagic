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
*/

require_once( __DIR__ . '/../../../maintenance/Maintenance.php' );

class MigrateCollation extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->mDescription = "Convert table to collation";
	}

	public function execute() {
		global $wgDBname;

		$dbw = wfGetDB( DB_MASTER );

		$this->output( "Database: $wgDBname\n" );
		$dbw->query( "ALTER DATABASE $wgDBname CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci" );

		$getTables = $dbw->listTables();

		foreach ( $getTables as $table ) {
			global $wgDBname;

			if ( !$table ) {
				continue;
			}

			$this->output( "Table: {$table}\n" );
			$dbw->query( "ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

			$res = $dbw->query( "SHOW FULL COLUMNS FROM $table" );
			$row = $dbw->fetchObject( $res );

			$field = $row->Field;
			if ( !$row || !$field ) {
				continue;
			}

			if ( isset( $row->Collation ) && preg_match( '/^latin1*/', $row->Collation ) ) {
				$this->output( "Field: $field\n" );
				$dbw->query( "UPDATE $table SET $field = CONVERT(BINARY CONVERT($field USING latin1) USING utf8);" );
			}
		}
	}
}

$maintClass = 'MigrateCollation';
require_once RUN_MAINTENANCE_IF_MAIN;
