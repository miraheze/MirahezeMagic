<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Shell\Shell;
use Miraheze\ImportDump\Hooks\ImportDumpJobAfterImportHook;
use Miraheze\ImportDump\Hooks\ImportDumpJobGetFileHook;
use Miraheze\ImportDump\ImportDumpRequestManager;
use Wikimedia\Rdbms\IConnectionProvider;

class ImportDump implements
	ImportDumpJobAfterImportHook,
    ImportDumpJobGetFileHook
{

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/**
	 * @inheritDoc
	 * @param ImportDumpRequestManager $requestManager @phan-unused-param
	 */
	public function onImportDumpJobAfterImport( $filePath, $requestManager ): void {
		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];
		Shell::command( '/bin/rm', $filePath )
			->limits( $limits )
			->disableSandbox()
			->execute();
	}

    /** @inheritDoc */
	public function onImportDumpJobGetFile( &$filePath, $requestManager ): void {
		global $wmgSwiftPassword;

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-importdump' );
		$container = $dbr->getDomainID() === 'metawikibeta' ?
			'miraheze-metawikibeta-local-public' :
			'miraheze-metawiki-local-public';

		$limits = [ 'memory' => 0, 'filesize' => 0, 'time' => 0, 'walltime' => 0 ];

		Shell::command(
			'swift', 'download',
			$container,
			$requestManager->getSplitFilePath(),
			'-o', $filePath,
			'-A', 'https://swift-lb.wikitide.net/auth/v1.0',
			'-U', 'mw:media',
			'-K', $wmgSwiftPassword
		)->limits( $limits )
			->disableSandbox()
			->execute();
	}
}
