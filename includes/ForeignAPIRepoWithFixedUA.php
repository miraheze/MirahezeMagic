<?php

namespace Miraheze\MirahezeMagic;

use MediaWiki\FileRepo\ForeignAPIRepo;
use const MW_VERSION;

class ForeignAPIRepoWithFixedUA extends ForeignAPIRepo {

	public static function getUserAgent(): string {
		$mediaWikiVersion = 'MediaWiki/' . MW_VERSION;
		return "$mediaWikiVersion (https://miraheze.org; tech@miraheze.org) ForeignAPIRepo/T400881";
	}
}
