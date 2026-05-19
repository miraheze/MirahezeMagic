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
 * @author Universal Omega
 * @version 2.0
 */

use MediaWiki\Maintenance\Maintenance;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\StaticArrayWriter;

class GenerateExtensionDatabaseList extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generate database list(s) of all wikis with given extension or skin enabled within ManageWiki.' );

		$desc = 'Extension or skin to generate database list for. ' .
			'This option may be passed multiple times to generate multiple database lists at once.';

		$this->addOption( 'directory', 'Directory to store the list files in.', true, true );
		$this->addOption( 'extension', $desc, true, true, multiOccurrence: true );
	}

	public function execute() {
		$extArray = $this->getOption( 'extension' );
		$directory = $this->getOption( 'directory' );

		$databaseUtils = $this->getServiceContainer()->get( 'ManageWikiDatabaseUtils' );
		$dbr = $databaseUtils->getGlobalReplicaDB();

		foreach ( $extArray as $ext ) {
			$mwSettings = $dbr->newSelectQueryBuilder()
				->table( 'mw_settings' )
				->fields( [ 's_dbname', 's_extensions' ] )
				->where( $dbr->expr( 's_extensions', IExpression::LIKE,
					new LikeValue( $dbr->anyString(), $ext, $dbr->anyString() )
				) )
				->caller( __METHOD__ )
				->fetchResultSet();

			$list = [];
			foreach ( $mwSettings as $row ) {
				if ( in_array( $ext, json_decode( $row->s_extensions, true ) ?? [], true ) ) {
					$list[$row->s_dbname] = [];
				}
			}

			if ( $list ) {
				$contents = StaticArrayWriter::write( [ 'databases' => $list ], 'Automatically generated' );
				file_put_contents( "$directory/$ext.php", $contents, LOCK_EX );
				continue;
			}

			$this->output( "No wikis are using $ext so a database list was not generated for it.\n" );
		}
	}
}

// @codeCoverageIgnoreStart
return GenerateExtensionDatabaseList::class;
// @codeCoverageIgnoreEnd
