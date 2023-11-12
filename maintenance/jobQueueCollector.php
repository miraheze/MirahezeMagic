<?php

require_once __DIR__ . '/../../../maintenance/Maintenance.php';

class JobQueueCollector extends Maintenance {
	public function __construct() {
		parent::__construct();

		$this->addDescription( 'Generates JobQueue metrics for prometheus' );
	}

	public function execute() {
		global $wgJobTypeConf;

		if ( !isset( $wgJobTypeConf['default']['redisServer'] ) || !$wgJobTypeConf['default']['redisServer'] ) {
			return;
		}

		$hostAndPort = IPUtils::splitHostAndPort( $wgJobTypeConf['default']['redisServer'] );

		if ( $hostAndPort === false ) {
			return false;
		}

		$queues = [
			'l-unclaimed',
			'z-abandoned'
		];

		$jobs = [
			'*',
			'AssembleUploadChunks',
			'CentralAuthCreateLocalAccountJob',
			'CentralAuthUnattachUserJob',
			'ChangeDeletionNotification',
			'ChangeNotification',
			'ChangeVisibilityNotification',
			'CleanTermsIfUnused',
			'CreateWikiJob',
			'DataDumpGenerateJob',
			'DeleteJob',
			'DeleteTranslatableBundleJob',
			'DispatchChangeDeletionNotification',
			'DispatchChangeVisibilityNotification',
			'DispatchChanges',
			'EchoNotificationDeleteJob',
			'EchoNotificationJob',
			'EchoPushNotificationRequest',
			'EntityChangeNotification',
			'GlobalNewFilesDeleteJob',
			'GlobalNewFilesInsertJob',
			'GlobalNewFilesMoveJob',
			'GlobalUserPageLocalJobSubmitJob',
			'InitImageDataJob',
			'LocalGlobalUserPageCacheUpdateJob',
			'LocalPageMoveJob',
			'LocalRenameUserJob',
			'LoginNotifyChecks',
			'MDCreatePage',
			'MDDeletePage',
			'MWScriptJob',
			'MassMessageJob',
			'MassMessageServerSideJob',
			'MassMessageSubmitJob',
			'MessageGroupStatesUpdaterJob',
			'MessageGroupStatsRebuildJob',
			'MessageIndexRebuildJob',
			'MessageUpdateJob',
			'MoveTranslatableBundleJob',
			'NamespaceMigrationJob',
			'PageProperties',
			'parsoidCachePrewarm',
			'PublishStashedFile',
			'PurgeEntityData',
			'RecordLintJob',
			'RemovePIIJob',
			'RenderTranslationPageJob',
			'RequestWikiAIJob',
			'SetContainersAccessJob',
			'SMW\\ChangePropagationClassUpdateJob',
			'SMW\\ChangePropagationDispatchJob',
			'SMW\\ChangePropagationUpdateJob',
			'SMW\\EntityIdDisposerJob',
			'SMW\\FulltextSearchTableRebuildJob',
			'SMW\\FulltextSearchTableUpdateJob',
			'SMW\\PropertyStatisticsRebuildJob',
			'SMW\\RefreshJob',
			'SMW\\UpdateDispatcherJob',
			'SMW\\UpdateJob',
			'SMWRefreshJob',
			'SMWUpdateJob',
			'TTMServerMessageUpdateJob',
			'ThumbnailRender',
			'TranslatableBundleDeleteJob',
			'TranslatableBundleMoveJob',
			'TranslateRenderJob',
			'TranslateSandboxEmailJob',
			'TranslationNotificationsEmailJob',
			'TranslationNotificationsSubmitJob',
			'TranslationsUpdateJob',
			'UpdateMessageBundle',
			'UpdateRepoOnDelete',
			'UpdateRepoOnMove',
			'UpdateTranslatablePageJob',
			'UpdateTranslatorActivity',
			'activityUpdateJob',
			'cargoPopulateTable',
			'categoryMembershipChange',
			'cdnPurge',
			'clearUserWatchlist',
			'clearWatchlistNotifications',
			'compileArticleMetadata',
			'constraintsRunCheck',
			'constraintsTableUpdate',
			'crosswikiSuppressUser',
			'deleteLinks',
			'deletePage',
			'dtImport',
			'edReparse',
			'enotifNotify',
			'enqueue',
			'fixDoubleRedirect',
			'flaggedrevs_CacheUpdate',
			'globalUsageCachePurge',
			'htmlCacheUpdate',
			'menteeOverviewUpdateDataForMentor',
			'newUserMessageJob',
			'newcomerTasksCacheRefreshJob',
			'null',
			'pageFormsCreatePage',
			'pageSchemasCreatePage',
			'reassignMenteesJob',
			'recentChangesUpdate',
			'refreshLinks',
			'refreshLinksDynamic',
			'refreshLinksPrioritized',
			'renameUser',
			'revertedTagUpdate',
			'sendMail',
			'setUserMentorDatabaseJob',
			'smw.changePropagationClassUpdate',
			'smw.changePropagationDispatch',
			'smw.changePropagationUpdate',
			'smw.deferredConstraintCheckUpdateJob',
			'smw.elasticFileIngest',
			'smw.elasticIndexerRecovery',
			'smw.entityIdDisposer',
			'smw.fulltextSearchTableRebuild',
			'smw.fulltextSearchTableUpdate',
			'smw.parserCachePurgeJob',
			'smw.propertyStatisticsRebuild',
			'smw.refresh',
			'smw.update',
			'smw.updateDispatcher',
			'updateBetaFeaturesUserCounts',
			'userEditCountInit',
			'userGroupExpiry',
			'userOptionsUpdate',
			'watchlistExpiry',
			'webVideoTranscode',
			'webVideoTranscodePrioritized',
			'wikibase-InjectRCRecords',
			'wikibase-addUsagesForPage'
		];

		try {
			$redis = new Redis();
			$redis->connect( $hostAndPort[0], $hostAndPort[1] );
			$redis->auth( $wgJobTypeConf['default']['redisConfig']['password'] );

			$result = [];
			foreach ( $jobs as $job ) {
				foreach ( $queues as $queue ) {
					$lsum = 0;
					$lkeys = $redis->keys( "*:jobqueue:$job:$queue" );
					foreach ( $lkeys as $lkey ) {
						if ( str_contains( $queue, 'l-unclaimed' ) ) {
							$lsum = $lsum + (int)$redis->zCard( $lkey );
						} else {
							$lsum = $lsum + (int)$redis->lLen( $lkey );
						}
					}

					$result["$job-$queue"] = (string)$lsum;
				}
			}
			$this->output( print_r( $result, true ) );
		} catch ( Throwable $ex ) {
			// empty
		}
	}
}

$maintClass = 'JobQueueCollector';
require_once RUN_MAINTENANCE_IF_MAIN;
