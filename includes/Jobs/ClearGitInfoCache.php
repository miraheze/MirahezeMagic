<?php

namespace Miraheze\DataDump\Jobs;

use Job;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\User;
use Miraheze\DataDump\ConfigNames;
use Miraheze\DataDump\Services\DataDumpFileBackend;
use MWExceptionHandler;
use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

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
