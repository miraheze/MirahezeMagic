class GlobalNewFilesHooks {
	public static function onUploadComplete( &$uploadBase ) {
		global $wgCreateWikiDatabase, $wmgPrivateWiki;

		$uploadedFile = $uploadBase->getLocalFile();

		$dbw = wfGetDB( DB_MASTER, [], $wgCreateWikiDatabase );

		$dbw->insert(
			'gnf_files',
			[
				'files_dbname' => $wgDBname,
				'files_url' => $uploadedFile->getViewURL(),
				'files_page' => $uploadedFile->getDescriptionUrl(),
				'files_name' => $uploadedFile->getName(),
				'files_user' => $uploadedFile->getUser(),
				'files_private' => (int)$wmgPrivateWiki,
				'files_timestamp' => $dbw->timestamp()
			]
		);
	}
}
