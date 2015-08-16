<?php
if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'MirahezeMagic' );
	return;
} else {
	die( 'This version requires MediaWiki 1.25+' );
}
