<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Shell\Shell;
use Miraheze\ImportDump\ConfigNames;
use Miraheze\ImportDump\Hooks\ImportDumpGetTargetStateHook;
use Miraheze\ImportDump\Hooks\ImportDumpJobAfterImportHook;
use Miraheze\ImportDump\Hooks\ImportDumpJobGetFileHook;
use Miraheze\ImportDump\Hooks\ImportDumpRequestStatusUpdateHook;
use Miraheze\ImportDump\Request\RequestManager;
use Miraheze\ImportDump\Request\RequestStatus;
use Miraheze\ImportDump\Request\RequestType;
use Miraheze\ManageWiki\Helpers\Factories\DataStoreFactory;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;
use function array_filter;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function explode;
use function in_array;

class ImportDump implements
	ImportDumpGetTargetStateHook,
	ImportDumpJobAfterImportHook,
	ImportDumpJobGetFileHook,
	ImportDumpRequestStatusUpdateHook
{

	private const array LIMITS = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

	public function __construct(
		private readonly DataStoreFactory $dataStoreFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly ModuleFactory $moduleFactory,
		private readonly ServiceOptions $options,
	) {
	}

	public static function factory(
		Config $mainConfig,
		DataStoreFactory $dataStoreFactory,
		IConnectionProvider $connectionProvider,
		ModuleFactory $moduleFactory
	): self {
		return new self(
			$dataStoreFactory,
			$connectionProvider,
			$moduleFactory,
			new ServiceOptions(
				[ ConfigNames::FileExpiryDays, 'MirahezeMagicSwiftKey' ],
				$mainConfig
			)
		);
	}

	/** @inheritDoc */
	public function onImportDumpGetTargetState(
		string $target,
		array $requesterGroups,
		?array &$extensions,
		array &$settings,
		array &$rights
	): void {
		$extensions = $this->moduleFactory->extensions( $target )->listNames();
		$settings['allowEntityImport'] = (bool)$this->moduleFactory
			->settings( $target )->list( 'wmgAllowEntityImport' );

		// The live permissions of ManageWiki leave out the additional rights and the read right
		// of private wikis, so the merged data is used. ManageWiki gives everyone read on a wiki
		// that is not private, whether or not the group has a row.
		$isPrivate = $this->dataStoreFactory->newInstance( $target )->isPrivate();
		$permissions = $this->moduleFactory->permissions( $target )->getCachedData( $isPrivate );

		foreach ( [ '*', 'user', ...$requesterGroups ] as $group ) {
			$rights = array_merge( $rights, $permissions[$group]['permissions'] ?? [] );
		}

		if ( !$isPrivate ) {
			$rights[] = 'read';
		}

		$rights = array_values( array_unique( $rights ) );
	}

	/**
	 * @inheritDoc
	 * @param RequestManager $requestManager @phan-unused-param
	 */
	public function onImportDumpJobAfterImport(
		string $filePath,
		RequestManager $requestManager
	): void {
		Shell::command( '/bin/rm', $filePath )
			->limits( self::LIMITS )
			->disableSandbox()
			->execute();
	}

	/** @inheritDoc */
	public function onImportDumpJobGetFile(
		string &$filePath,
		RequestManager $requestManager
	): void {
		Shell::command(
			'swift', 'download',
			$this->getContainer(),
			$requestManager->getSplitFilePath(),
			'-o', $filePath,
			...$this->getAuthArguments()
		)->limits( self::LIMITS )
			->disableSandbox()
			->execute();
	}

	/** @inheritDoc */
	public function onImportImagesJobGetFiles(
		string $directory,
		RequestManager $requestManager,
		int $offset,
		int $limit
	): void {
		$container = $this->getContainer();
		$authArguments = $this->getAuthArguments();
		$prefix = $requestManager->getSplitFilePath() . '/';

		$objects = [ '--prefix', $prefix, '--remove-prefix' ];
		if ( $limit > 0 ) {
			$list = Shell::command( 'swift', 'list', $container, '--prefix', $prefix, ...$authArguments )
				->limits( self::LIMITS )
				->disableSandbox()
				->execute();

			$all = array_values( array_filter( explode( "\n", $list->getStdout() ) ) );
			$names = array_slice( $all, $offset, $limit );
			if ( !$names ) {
				return;
			}

			$objects = $names;
		}

		Shell::command(
			'swift', 'download',
			$container,
			...[ ...$objects, '-D', $directory, ...$authArguments ]
		)->limits( self::LIMITS )
			->disableSandbox()
			->execute();
	}

	/**
	 * @inheritDoc
	 * @param RequestStatus $oldStatus @phan-unused-param
	 */
	public function onImportDumpRequestStatusUpdate(
		RequestManager $requestManager,
		RequestStatus $newStatus,
		RequestStatus $oldStatus
	): void {
		if ( !in_array( $newStatus, RequestManager::EXPIRABLE_STATUSES, true ) ) {
			return;
		}

		$days = (int)( $this->options->get( ConfigNames::FileExpiryDays )[$newStatus->value] ?? 0 );
		if ( $days <= 0 ) {
			return;
		}

		// The files of an images request are removed by the ExpireFiles maintenance script instead,
		// because every image would need an expiry of its own.
		if ( $requestManager->getType() === RequestType::Images ) {
			return;
		}

		$objectPath = $requestManager->getSplitFilePath();
		$container = $this->getContainer();
		$authArguments = $this->getAuthArguments();
		$expiry = 'X-Delete-After: ' . ( $days * 86400 );

		DeferredUpdates::addCallableUpdate( static function () use (
			$objectPath, $container, $authArguments, $expiry
		): void {
			$logger = LoggerFactory::getInstance( 'MirahezeMagic' );

			try {
				$result = Shell::command(
					'swift', 'post',
					'-H', $expiry,
					$container,
					$objectPath,
					...$authArguments
				)->limits( self::LIMITS )
					->disableSandbox()
					->execute();

				if ( $result->getExitCode() !== 0 ) {
					$logger->warning( 'Failed to set expiry on {object}: {error}', [
						'object' => $objectPath,
						'error' => $result->getStderr(),
					] );
				}
			} catch ( Throwable $e ) {
				$logger->error( 'Failed to set expiry on {object}', [
					'object' => $objectPath,
					'exception' => $e,
				] );
			}
		} );
	}

	private function getContainer(): string {
		$dbr = $this->connectionProvider->getReplicaDatabase( RequestManager::VIRTUAL_DOMAIN );
		return $dbr->getDomainID() === 'metawikibeta' ?
			'miraheze-metawikibeta-local-public' :
			'miraheze-metawiki-local-public';
	}

	/** @return string[] */
	private function getAuthArguments(): array {
		return [
			'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
			'-U', 'mw:media',
			'-K', $this->options->get( 'MirahezeMagicSwiftKey' ),
		];
	}
}
