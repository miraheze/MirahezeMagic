<?php

/**
 * Used to create the ElasticSearch index for new wikis
 *
 * @author Paladox
 */
class MirahezeMagicCreateWikiJob extends Job {
	public function __construct( $title, $params ) {
		parent::__construct( 'MirahezeMagicCreateWikiJob', $title, $params );
	}

	public function run() {
		$DBname = $this->params['wikidbname'];

		// Elasticsearch
		Shell::command(
			'/usr/bin/php',
			'/srv/mediawiki/w/extensions/CirrusSearch/maintenance/updateSearchIndexConfig.php',
			'--wiki',
			$DBname
		)->execute();

		Shell::command(
			'/usr/bin/php',
			'/srv/mediawiki/w/extensions/CirrusSearch/maintenance/forceSearchIndex.php',
			'--skipLinks',
			'--indexOnSkip',
			'--wiki',
			$DBname
		)->execute();

		Shell::command(
			'/usr/bin/php',
			'/srv/mediawiki/w/extensions/CirrusSearch/maintenance/forceSearchIndex.php',
			'--skipParse',
			'--wiki',
			$DBname
		)->execute();

		return true;
	}
}
