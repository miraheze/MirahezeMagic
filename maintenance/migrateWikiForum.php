<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class migrateWikiForum extends Maintenance {
	public function __construct() {
		parent::__construct();
	}

	public function execute() {
		global $wgDBname;

		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wfc_added_actor-to-wikiforum_category.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wfc_edited_actor-to-wikiforum_category.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wfc_deleted_actor-to-wikiforum_category.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wff_last_post_actor-to-wikiforum_forums.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wff_added_actor-to-wikiforum_forums.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wff_edited_actor-to-wikiforum_forums.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wff_deleted_actor-to-wikiforum_forums.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wft_actor-to-wikiforum_threads.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wft_deleted_actor-to-wikiforum_threads.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wft_edit_actor-to-wikiforum_threads.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wft_closed_actor-to-wikiforum_threads.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wft_last_post_actor-to-wikiforum_threads.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wfr_actor-to-wikiforum_replies.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wfr_deleted_actor-to-wikiforum_replies.sql" );
		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/sql/patches/actor/add-wfr_edit_actor-to-wikiforum_replies.sql" );

		exec( "php /srv/mediawiki/w/maintenance/sql.php --wiki $wgDBname /srv/mediawiki/w/extensions/WikiForum/maintenance/migrateOldWikiForumUserColumnsToActor.php" );
	}
}

$maintClass = 'migrateWikiForum';
require_once RUN_MAINTENANCE_IF_MAIN;
