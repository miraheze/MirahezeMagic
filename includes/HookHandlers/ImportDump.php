<?php

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
use Miraheze\ImportDump\RequestManager;
use Miraheze\ImportDump\RequestStatus;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Throwable;
use Wikimedia\Rdbms\IConnectionProvider;

class ImportDump implements
	ImportDumpGetTargetStateHook,
	ImportDumpJobAfterImportHook,
	ImportDumpJobGetFileHook,
	ImportDumpRequestStatusUpdateHook
{

	private const array EXPIRABLE_STATUSES = [
		RequestStatus::STATUS_COMPLETE,
		RequestStatus::STATUS_DECLINED,
		RequestStatus::STATUS_FAILED,
	];

	private const array LIMITS = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly ModuleFactory $moduleFactory,
		private readonly ServiceOptions $options,
	) {
	}

	public static function factory(
		Config $mainConfig,
		IConnectionProvider $connectionProvider,
		ModuleFactory $moduleFactory
	): self {
		return new self(
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

		$permissions = $this->moduleFactory->permissions( $target );
		foreach ( [ '*', 'user', ...$requesterGroups ] as $group ) {
			$rights = array_merge( $rights, $permissions->list( $group )['permissions'] );
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

	/**
	 * @inheritDoc
	 * @param string $oldStatus @phan-unused-param
	 */
	public function onImportDumpRequestStatusUpdate(
		RequestManager $requestManager,
		string $newStatus,
		string $oldStatus
	): void {
		if ( !in_array( $newStatus, self::EXPIRABLE_STATUSES, true ) ) {
			return;
		}

		$days = (int)( $this->options->get( ConfigNames::FileExpiryDays )[$newStatus] ?? 0 );
		if ( $days <= 0 ) {
			return;
		}

		$objectPath = $requestManager->getSplitFilePath();
		$command = [
			'swift', 'post',
			'-H', 'X-Delete-After: ' . ( $days * 86400 ),
			$this->getContainer(),
			$objectPath,
			...$this->getAuthArguments(),
		];

		DeferredUpdates::addCallableUpdate( static function () use ( $command, $objectPath ): void {
			$logger = LoggerFactory::getInstance( 'MirahezeMagic' );

			try {
				$result = Shell::command( $command )
					->limits( self::LIMITS )
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
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
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
