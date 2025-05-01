<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;

class UpdatePrivateAuthUrls extends Maintenance {

	public function execute() {
		$moduleFactory = $this->getServiceContainer()->get( 'ManageWikiModuleFactory' );
		$mwCore = $moduleFactory->coreLocal();

		if ( $mwCore->isPrivate() ) {
			$mwSettings = $moduleFactory->settingsLocal();
			$dbname = $this->getConfig()->get( MainConfigNames::DBname );
			foreach ( $mwSettings->list( var: null ) as $var => $val ) {
				if (
					is_string( $val ) &&
					str_contains( $val, "static.wikitide.net/$dbname" )
				) {
					$new = preg_replace( "/((http)?(s)?(:)?\/\/)?static.wikitide.net\/$dbname/", $this->getConfig()->get( MainConfigNames::Server ) . '/w/img_auth.php', $val );

					$this->output( "Updating {$var} for {$dbname} '{$val} => {$new}'\n" );

					$mwSettings->modify( [ $var => $new ], default: null );
					$mwSettings->commit();
				}
			}
		}
	}
}

// @codeCoverageIgnoreStart
return UpdatePrivateAuthUrls::class;
// @codeCoverageIgnoreEnd
