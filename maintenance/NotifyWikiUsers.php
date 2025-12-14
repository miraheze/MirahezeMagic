<?php

namespace Miraheze\MirahezeMagic\Maintenance;

use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Maintenance\Maintenance;

class NotifyWikiUsers extends Maintenance {

	/** @inheritDoc */
	public function __construct() {
		parent::__construct();

		$this->requireExtension( 'Echo' );

		$this->addDescription( 'Send a notification to a specific set of users in a wiki.' );

		$this->addOption( 'header', 'Header for the notification', required: true, withArg: true );
		$this->addOption( 'link', 'Link for the notification', withArg: true );
		$this->addOption( 'link-label', 'Link label for the notification', withArg: true );
		$this->addOption( 'permission', 'Notify all users that have this permission',
			withArg: true, multiOccurrence: true );
		$this->addOption( 'group', 'Notify all users that have this group',
			withArg: true, multiOccurrence: true );
	}

	/** @inheritDoc */
	public function execute(): void {
		$groups = $this->getOption( 'group' ) ?? [];
		$permissions = $this->getOption( 'permission' ) ?? [];

		if ( !$groups && !$permissions ) {
			$this->fatalError( 'Please specify at least one group or permission!' );
		}

		$header = $this->getOption( 'header' );
		$message = $this->getStdin( Maintenance::STDIN_ALL );
		$link = $this->getOption( 'link' );
		$linkLabel = $this->getOption( 'link-label', 'More information' );

		foreach ( $permissions as $permission ) {
			$groups = array_merge(
				$groups,
				$this->getServiceContainer()->getGroupPermissionsLookup()->getGroupsWithPermission( $permission )
			);
		}

		$dbr = $this->getReplicaDB();
		$users = [];
		foreach ( $groups as $group ) {
			$res = $dbr->newSelectQueryBuilder()
				->select( [ 'ug_user' ] )
				->from( 'user_groups' )
				->where( [
					'ug_group' => $group,
				] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				$users[$row->ug_user] = true;
			}
		}

		$users = array_keys( $users );
		$userCount = count( $users );

		$extra = [
			Event::RECIPIENTS_IDX => $users,
			'header' => $header,
			'message' => $message,
		];
		if ( $link !== null ) {
			$extra['link'] = $link;
			$extra['link-label'] = $linkLabel;
		}

		$this->output( "Sending notifications to $userCount users...\n " );
		Event::create(
			[
				'type' => 'mirahezemagic-tech-notification',
				'extra' => $extra,
			]
		);
	}

}

// @codeCoverageIgnoreStart
return NotifyWikiUsers::class;
// @codeCoverageIgnoreEnd
