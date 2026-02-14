<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Shell\Shell;
use Memcached;
use Miraheze\CreateWiki\Hooks\CreateWikiDeletionHook;
use Miraheze\CreateWiki\Hooks\CreateWikiRenameHook;
use Miraheze\CreateWiki\Hooks\CreateWikiStatePrivateHook;
use Miraheze\CreateWiki\Hooks\CreateWikiTablesHook;
use Miraheze\CreateWiki\Maintenance\SetContainersAccess;
use Miraheze\ManageWiki\ConfigNames;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Psr\Log\LoggerInterface;
use Redis;
use Throwable;
use Wikimedia\IPUtils;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\IConnectionProvider;

class CreateWiki implements
	CreateWikiDeletionHook,
	CreateWikiRenameHook,
	CreateWikiStatePrivateHook,
	CreateWikiTablesHook
{

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly FormatterFactory $formatterFactory,
		private readonly ModuleFactory $moduleFactory,
		private readonly RepoGroup $repoGroup,
		private readonly LoggerInterface $logger,
		private readonly ServiceOptions $options
	) {
	}

	public static function factory(
		Config $mainConfig,
		IConnectionProvider $connectionProvider,
		FormatterFactory $formatterFactory,
		ModuleFactory $moduleFactory,
		RepoGroup $repoGroup
	): self {
		return new self(
			$connectionProvider,
			$formatterFactory,
			$moduleFactory,
			$repoGroup,
			LoggerFactory::getInstance( 'MirahezeMagic' ),
			new ServiceOptions(
				[
					'EchoSharedTrackingDB',
					'GlobalUsageDatabase',
					'MirahezeMagicMemcachedServers',
					'MirahezeMagicSwiftKey',
					ConfigNames::Settings,
					MainConfigNames::JobTypeConf,
					MainConfigNames::LocalDatabases,
				],
				$mainConfig
			)
		);
	}

	/**
	 * @inheritDoc
	 * @param DBConnRef $cwdb @phan-unused-param
	 */
	public function onCreateWikiDeletion( DBConnRef $cwdb, string $dbname ): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase(
			$this->options->get( 'EchoSharedTrackingDB' )
		);

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'echo_unread_wikis' )
			->where( [ 'euw_wiki' => $dbname ] )
			->caller( __METHOD__ )
			->execute();

		if ( $this->options->get( 'GlobalUsageDatabase' ) ) {
			$gudDb = $this->connectionProvider->getPrimaryDatabase(
				$this->options->get( 'GlobalUsageDatabase' )
			);

			$gudDb->newDeleteQueryBuilder()
				->deleteFrom( 'globalimagelinks' )
				->where( [ 'gil_wiki' => $dbname ] )
				->caller( __METHOD__ )
				->execute();
		}

		foreach ( $this->options->get( MainConfigNames::LocalDatabases ) as $db ) {
			$mwSettings = $this->moduleFactory->settings( $db );
			foreach ( $this->options->get( ConfigNames::Settings ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$mwSettings->list( $var ) === $dbname
				) {
					$mwSettings->remove( [ $var ], default: null );
					$mwSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to delete for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', "miraheze-$dbname-",
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $this->options->get( 'MirahezeMagicSwiftKey' )
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $dbname . '-' ) ) {
				continue;
			}

			// Delete the container
			Shell::command(
				'swift', 'delete',
				$container,
				'--object-threads', '1',
				'--container-threads', '1',
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $this->options->get( 'MirahezeMagicSwiftKey' )
			)->limits( $limits )
				->disableSandbox()
				->execute();
		}

		$this->removeRedisKey( "*$dbname*" );
		$this->removeMemcachedKey( ".*$dbname.*" );
	}

	/**
	 * @inheritDoc
	 * @param DBConnRef $cwdb @phan-unused-param
	 */
	public function onCreateWikiRename(
		DBConnRef $cwdb,
		string $oldDbName,
		string $newDbName
	): void {
		$dbw = $this->connectionProvider->getPrimaryDatabase(
			$this->options->get( 'EchoSharedTrackingDB' )
		);

		$dbw->newUpdateQueryBuilder()
			->update( 'echo_unread_wikis' )
			->set( [ 'euw_wiki' => $newDbName ] )
			->where( [ 'euw_wiki' => $oldDbName ] )
			->caller( __METHOD__ )
			->execute();

		if ( $this->options->get( 'GlobalUsageDatabase' ) ) {
			$gudDb = $this->connectionProvider->getPrimaryDatabase(
				$this->options->get( 'GlobalUsageDatabase' )
			);

			$gudDb->newUpdateQueryBuilder()
				->update( 'globalimagelinks' )
				->set( [ 'gil_wiki' => $newDbName ] )
				->where( [ 'gil_wiki' => $oldDbName ] )
				->caller( __METHOD__ )
				->execute();
		}

		foreach ( $this->options->get( MainConfigNames::LocalDatabases ) as $db ) {
			$mwSettings = $this->moduleFactory->settings( $db );
			foreach ( $this->options->get( ConfigNames::Settings ) as $var => $setConfig ) {
				if (
					$setConfig['type'] === 'database' &&
					$mwSettings->list( $var ) === $oldDbName
				) {
					$mwSettings->modify( [ $var => $newDbName ], default: null );
					$mwSettings->commit();
				}
			}
		}

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		// Get a list of containers to download, and later upload for the wiki
		$containers = explode( "\n",
			trim( Shell::command(
				'swift', 'list',
				'--prefix', "miraheze-$oldDbName-",
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $this->options->get( 'MirahezeMagicSwiftKey' )
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout()
			)
		);

		foreach ( $containers as $container ) {
			// Just an extra precaution to ensure we don't select the wrong containers
			if ( !str_contains( $container, $oldDbName . '-' ) ) {
				continue;
			}

			// Get a list of all files in the container to ensure everything is present in new container later.
			$oldContainerList = Shell::command(
				'swift', 'list',
				$container,
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $this->options->get( 'MirahezeMagicSwiftKey' )
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout();

			// Download the container
			Shell::command(
				'swift', 'download',
				$container,
				'-D', wfTempDir() . '/' . $container,
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $this->options->get( 'MirahezeMagicSwiftKey' )
			)->limits( $limits )
				->disableSandbox()
				->execute();

			$newContainer = str_replace( $oldDbName, $newDbName, $container );

			// Upload to new container
			// We have to use exec here, as Shell::command does not work for this
			// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions
			exec( escapeshellcmd(
				implode( ' ', [
					'swift', 'upload',
					$newContainer,
					wfTempDir() . '/' . $container,
					'--object-name', '""',
					'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
					'-U', 'mw:media',
					'-K', $this->options->get( 'MirahezeMagicSwiftKey' ),
				] )
			) );

			$this->logger->debug( 'Container created: {container}',
				[ 'container' => $newContainer ]
			);

			$newContainerList = Shell::command(
				'swift', 'list',
				$newContainer,
				'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
				'-U', 'mw:media',
				'-K', $this->options->get( 'MirahezeMagicSwiftKey' )
			)->limits( $limits )
				->disableSandbox()
				->execute()->getStdout();

			if ( $newContainerList === $oldContainerList ) {
				// Everything has been correctly copied over
				// wipe files from the temp directory and delete old container

				// Delete the container
				Shell::command(
					'swift', 'delete',
					$container,
					'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
					'-U', 'mw:media',
					'-K', $this->options->get( 'MirahezeMagicSwiftKey' )
				)->limits( $limits )
					->disableSandbox()
					->execute();

				$this->logger->debug( 'Container deleted: {container}',
					[ 'container' => $newContainer ]
				);

				// Wipe from the temp directory
				Shell::command( '/bin/rm', '-rf', wfTempDir() . '/' . $container )
					->limits( $limits )
					->disableSandbox()
					->execute();
			} else {
				/**
				 * We need to log this, as otherwise all files may not have been succesfully
				 * moved to the new container, and they still exist locally. We should know that.
				 */
				$this->logger->warning(
					'The rename of wiki {old} to {new} may not have been successful. Files still exist locally in {temp} and the Swift containers for the old wiki still exist.',
					[
						'new' => $newDbName,
						'old' => $oldDbName,
						'temp' => wfTempDir(),
					]
				);
			}
		}

		Shell::makeScriptCommand(
			SetContainersAccess::class,
			[ '--wiki', $newDbName ]
		)->limits( $limits )->execute();

		$this->removeRedisKey( "*$oldDbName*" );
		$this->removeMemcachedKey( ".*$oldDbName.*" );
	}

	/** @inheritDoc */
	public function onCreateWikiStatePrivate( string $dbname ): void {
		$localRepo = $this->repoGroup->getLocalRepo();
		$sitemaps = $localRepo->getBackend()->getTopFileList( [
			'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps',
			'adviseStat' => false,
		] );

		foreach ( $sitemaps as $sitemap ) {
			$status = $localRepo->getBackend()->quickDelete( [
				'src' => $localRepo->getZonePath( 'public' ) . "/sitemaps/$sitemap",
			] );

			if ( !$status->isOK() ) {
				$statusFormatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );

				/**
				 * We need to log this, as otherwise the sitemaps may
				 * not be being deleted for private wikis. We should know that.
				 */
				$statusMessage = $statusFormatter->getWikiText( $status );
				$this->logger->warning( 'Sitemap "{sitemap}" failed to delete for {dbname}: {status}', [
					'dbname' => $dbname,
					'sitemap' => $sitemap,
					'status' => $statusMessage,
				] );
			}
		}

		$localRepo->getBackend()->clean( [ 'dir' => $localRepo->getZonePath( 'public' ) . '/sitemaps' ] );
	}

	/** @inheritDoc */
	public function onCreateWikiTables( array &$tables ): void {
		$tables['cuci_wiki_map'] = 'ciwm_wiki';
		$tables['localnames'] = 'ln_wiki';
		$tables['localuser'] = 'lu_wiki';
	}

	private function removeRedisKey( string $key ): void {
		$jobTypeConf = $this->options->get( MainConfigNames::JobTypeConf );
		if ( !isset( $jobTypeConf['default']['redisServer'] ) || !$jobTypeConf['default']['redisServer'] ) {
			return;
		}

		$hostAndPort = IPUtils::splitHostAndPort( $jobTypeConf['default']['redisServer'] );

		if ( $hostAndPort ) {
			try {
				$redis = new Redis();
				$redis->connect( $hostAndPort[0], $hostAndPort[1] );
				$redis->auth( $jobTypeConf['default']['redisConfig']['password'] ?? '' );
				$redis->del( $redis->keys( $key ) );
			} catch ( Throwable ) {
				// empty
			}
		}
	}

	private function removeMemcachedKey( string $key ): void {
		$memcachedServers = $this->options->get( 'MirahezeMagicMemcachedServers' );

		try {
			foreach ( $memcachedServers as $memcachedServer ) {
				$memcached = new Memcached();
				$memcached->addServer( $memcachedServer[0], (int)$memcachedServer[1] );

				// Fetch all keys
				$keys = $memcached->getAllKeys();
				if ( !is_array( $keys ) ) {
					continue;
				}

				foreach ( $keys as $item ) {
					// Decide which keys to delete
					if ( preg_match( "/$key/", $item ) ) {
						$memcached->delete( $item );
					}
				}
			}
		} catch ( Throwable ) {
			// empty
		}
	}
}
