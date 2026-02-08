<?php

namespace Miraheze\MirahezeMagic;

use MediaWiki\FileRepo\ForeignAPIRepo;
use const MW_VERSION;

class ForeignAPIRepoWithFixedUA extends ForeignAPIRepo {

	public static function getUserAgent(): string {
		global $wgDBname;

		$mediaWikiVersion = MW_VERSION;
		return "QuickInstantCommons/$mediaWikiVersion MediaWiki/$mediaWikiVersion $wgDBname (https://miraheze.org; tech@miraheze.org)";
	}
}
