<?php

namespace Miraheze\DataDump\Jobs;

use Job;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use Miraheze\DataDump\Services\DataDumpFileBackend;
use Wikimedia\Rdbms\IConnectionProvider;

class ClearGitInfoCache extends Job {

	public const JOB_NAME = 'ClearGitInfoCache';

	private Config $config;
	private IConnectionProvider $connectionProvider;
	private DataDumpFileBackend $fileBackend;

	private array $arguments;
	private string $fileName;
	private string $type;

	public function __construct(
		array $params,
		ConfigFactory $configFactory,
		IConnectionProvider $connectionProvider,
		DataDumpFileBackend $fileBackend
	) {
		parent::__construct( self::JOB_NAME, $params );

		$this->arguments = $params['arguments'];
		$this->fileName = $params['fileName'];
		$this->type = $params['type'];

		$this->config = $configFactory->makeConfig( 'DataDump' );
		$this->connectionProvider = $connectionProvider;
		$this->fileBackend = $fileBackend;
	}

	public function run(): bool {
		$cache = MediaWikiServices::getInstance()->getObjectCacheFactory()->getInstance( CACHE_ANYTHING );

		return true;
	}
}
