<?php

class GlobalNewFilesHooks {
	public static function onUploadComplete( &$uploadBase ) {
		global $wgCreateWikiDatabase, $wgDBname, $wmgPrivateWiki, $wgServer;

		$uploadedFile = $uploadBase->getLocalFile();

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$dbw->insert(
			'gnf_files',
			[
				'files_dbname' => $wgDBname,
				'files_url' => $uploadedFile->getViewURL(),
				'files_page' => $wgServer . $uploadedFile->getDescriptionUrl(),
				'files_name' => $uploadedFile->getName(),
				'files_user' => $uploadedFile->getUser(),
				'files_private' => (int)$wmgPrivateWiki,
				'files_timestamp' => $dbw->timestamp()
			]
		);
	}

	/**
	 * Hook to FileDeleteComplete
	 * @param File $file
	 * @param File $oldimage
	 * @param Article $article
	 * @param User $user
	 * @param string $reason
	 */
	public static function onFileDeleteComplete( $file, $oldimage, $article, $user, $reason ) {
		global $wgCreateWikiDatabase, $wgDBname;

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$dbw->delete(
			'gnf_files',
			[
				'files_dbname' => $wgDBname,
				'files_name' => $file->getTitle()->getDBkey(),
			]
		);
	}
	
	public static function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		global $wgCreateWikiDatabase, $wgDBname;
		if ( $wgCreateWikiDatabase === $wgDBname ) {
			$updater->addExtensionTable( 
				'gnf_files',
				__DIR__ . '/../../sql/gnf_files.sql' 
			);

			$updater->modifyField( 
				'gnf_files', 
				'files_timestamp', 
				__DIR__ . '/../../sql/patch-gnf_files-binary.sql' 
			);
		}
		
		return true;
	}
}
