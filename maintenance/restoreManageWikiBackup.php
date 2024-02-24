<?php

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
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
 * @version 1.0
 */

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

use Miraheze\CreateWiki\CreateWikiJson;
use Miraheze\ManageWiki\Helpers\ManageWikiExtensions;

class RestoreManageWikiBackup extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Restore a backup generated by the generateManageWikiBackup.php script. This will override all currently set settings!' );

		$this->addOption( 'filename', 'Filename to restore json from.', true, true );
	}

	public function execute() {
		$dbName = $this->getConfig()->get( 'DBname' );

		if ( $dbName === 'default' ) {
			$this->fatalError( 'Invalid wiki. You can not overwrite default.' );
		}

		$dbw = $this->getDB( DB_PRIMARY, [], $this->getConfig()->get( 'CreateWikiDatabase' ) );

		$backupFile = $this->getOption( 'filename' );
		if ( !file_exists( $backupFile ) ) {
			$this->fatalError( "Backup file $backupFile does not exist." );
		}

		$json = file_get_contents( $backupFile );
		$data = json_decode( $json, true );

		// Just check for namespaces to confirm it is indeed a ManageWiki backup.
		if ( !isset( $data['namespaces'] ) ) {
			$this->fatalError( 'Invalid backup file.' );
		}

		$confirm = readline( "Are you sure you want to restore the ManageWiki settings from $backupFile to $dbName? This will overwrite all current settings on the wiki! (y/n) " );
		if ( strtolower( $confirm ) !== 'y' ) {
			$this->fatalError( 'Aborted.', 2 );
		}

		$dbw->delete( 'mw_namespaces', [ 'ns_dbname' => $dbName ] );
		$dbw->delete( 'mw_permissions', [ 'perm_dbname' => $dbName ] );
		$dbw->delete( 'mw_settings', [ 's_dbname' => $dbName ] );

		foreach ( $data['namespaces'] as $name => $nsData ) {
			foreach ( $nsData as $key => $value ) {
				unset( $nsData[$key] );

				$prefix = 'ns_';
				if ( $key === 'id' ) {
					$prefix = 'ns_namespace_';
				}

				if ( $key === 'contentmodel' ) {
					$key = 'content_model';
				}

				if ( $key === 'additional' || $key === 'aliases' ) {
					$value = json_encode( $value );
				}

				$nsData[$prefix . $key] = $value;
			}

			$nsData['ns_namespace_name'] = $name;
			$nsData['ns_dbname'] = $dbName;

			$dbw->insert( 'mw_namespaces', $nsData );
		}

		if ( isset( $data['permissions'] ) ) {
			foreach ( $data['permissions'] as $group => $permData ) {
				foreach ( $permData as $key => $value ) {
					unset( $permData[$key] );

					if ( $key === 'addself' ) {
						$key = 'addgroupstoself';
					}

					if ( $key === 'removeself' ) {
						$key = 'removegroupsfromself';
					}

					$permData['perm_' . $key] = json_encode( $value );
				}

				$permData['perm_group'] = $group;
				$permData['perm_dbname'] = $dbName;

				$dbw->insert( 'mw_permissions', $permData );
			}
		}

		if ( isset( $data['settings'] ) || isset( $data['extensions'] ) ) {
			$settingsData = [];
			$settingsData['s_dbname'] = $dbName;

			if ( isset( $data['settings'] ) ) {
				$settingsData['s_settings'] = json_encode( $data['settings'] );
			}

			$dbw->insert( 'mw_settings', $settingsData );

			if ( isset( $data['extensions'] ) ) {
				$mwExt = new ManageWikiExtensions( $dbName );
				$mwExt->overwriteAll( $data['extensions'] );
				$mwExt->commit();
			}
		}

		$cWJ = new CreateWikiJson( $dbName );
		$cWJ->resetWiki();

		$this->output( "Successfully restored the backup from '{$this->getOption( 'filename' )}'.\n" );
	}
}

$maintClass = RestoreManageWikiBackup::class;
require_once RUN_MAINTENANCE_IF_MAIN;