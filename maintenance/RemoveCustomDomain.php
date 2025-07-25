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
 * @author MacFan4000
 * @version 1.0
 */

use MediaWiki\Maintenance\Maintenance;

class RemoveCustomDomain extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Removes the custom domain for the specified wiki.' );
		$this->addOption( 'dbname', 'Wiki DB name to remove the custom domain for' );

		$this->requireExtension( 'CreateWiki' );
	}

	public function execute(): void {
		$this->output( "Removing custom domain for " . $this->getOption( 'dbname' ) . "\n" );
		$moduleFactory = $this->getServiceContainer()->get( 'ManageWikiModuleFactory' );
		$mwCore = $moduleFactory->core( $this->getOption( 'dbname' ) );
		$mwCore->setServerName( "" );
		$mwCore->commit();
		$this->output( "Custom domain was sucesfully rmeoved\n" );
	}
}

// @codeCoverageIgnoreStart
return RemoveCustomDomain::class;
// @codeCoverageIgnoreEnd
