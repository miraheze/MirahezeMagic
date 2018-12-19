<?php

class SpecialGlobalNewFiles extends SpecialPage {

	function __construct() {
		parent::__construct( 'GlobalNewFiles' );
	}

	function execute( $par ) {
		$this->setHeaders();
		$this->outputHeader();

		$out = $this->getOutput();

		$pager = new GlobalNewFilesPager();
		$table = $pager->getBody();

		$out->addHTML( $pager->getNavigationBar() . $table . $pager->getNavigationBar() );
	}

	protected function getGroupName() {
		return 'other';
	}
}
