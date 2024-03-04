<?php

namespace Miraheze\MirahezeMagic\Specials;

/**
 * Creates Special:VanishUser for Stewards to use to vanish users easily.
 * Derived from RemovePII's Special:RemovePII code located at
 * https://github.com/miraheze/RemovePII/blob/master/includes/SpecialRemovePII.php
 * Originally written by Universal Omega.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @author Universal Omega
 * @author Agent Isai
 * @version 1.0
 */

use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUser;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserDatabaseUpdates;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserStatus;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserValidator;
use MediaWiki\Extension\CentralAuth\User\CentralAuthAntiSpoofManager;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\CentralAuth\Widget\HTMLGlobalUserTextField;
use MediaWiki\Html\Html;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\UserFactory;

class SpecialVanishUser extends FormSpecialPage {

	/** @var CentralAuthAntiSpoofManager|null */
	private $centralAuthAntiSpoofManager;

	/** @var CentralAuthDatabaseManager|null */
	private $centralAuthDatabaseManager;

	/** @var GlobalRenameUserValidator|null */
	private $globalRenameUserValidator;

	/** @var JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 * @param UserFactory $userFactory
	 * @param ?CentralAuthAntiSpoofManager $centralAuthAntiSpoofManager
	 * @param ?CentralAuthDatabaseManager $centralAuthDatabaseManager
	 * @param ?GlobalRenameUserValidator $globalRenameUserValidator
	 */
	public function __construct(
		JobQueueGroupFactory $jobQueueGroupFactory,
		UserFactory $userFactory,
		?CentralAuthAntiSpoofManager $centralAuthAntiSpoofManager,
		?CentralAuthDatabaseManager $centralAuthDatabaseManager,
		?GlobalRenameUserValidator $globalRenameUserValidator
	) {
		parent::__construct( 'VanishUser', 'centralauth-rename' );

		$this->centralAuthAntiSpoofManager = $centralAuthAntiSpoofManager;
		$this->centralAuthDatabaseManager = $centralAuthDatabaseManager;
		$this->globalRenameUserValidator = $globalRenameUserValidator;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 */
	public function execute( $par ) {
		$this->requireLogIn();
		$this->getOutput()->disallowUserJs();
		$this->checkPermissions();

		parent::execute( $par );
	}

	/**
	 * @return array|string
	 */
	protected function getFormFields() {
		$formDescriptor = [];

		$formDescriptor['warning'] = [
			'type' => 'info',
			'help' => Html::warningBox( $this->msg( 'vanishuser-warning' )->parse() ),
			'raw-help' => true,
			'hide-if' => [ '!==', 'wpconfirm', '1' ]
		];

		$formDescriptor['oldname'] = [
			'class' => HTMLGlobalUserTextField::class,
			'required' => true,
			'label-message' => 'removepii-oldname-label',
		];

		$formDescriptor['newname'] = [
			'type' => 'text',
			'default' => 'Vanished user ' . substr( sha1( random_bytes( 10 ) ), 0, 32 ),
			'required' => true,
			'label-message' => 'removepii-newname-label',
		];

		$formDescriptor['reason'] = [
			'type' => 'text',
			'required' => true,
			'label-message' => 'centralauth-admin-reason',
		];

		$formDescriptor['confirm'] = [
			'type' => 'check',
			'required' => true,
			'label-message' => 'vanishuser-action-label',
			'help-message' => 'vanishuser-action-help',
		];

		return $formDescriptor;
	}

	/**
	 * @param array $formData
	 * @return Status
	 */
	public function validateCentralAuth( array $formData ) {
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'CentralAuth' ) ) {
			return Status::newFatal( 'removepii-centralauth-notinstalled' );
		}

		$oldUser = $this->userFactory->newFromName( $formData['oldname'] );
		if ( !$oldUser ) {
			return Status::newFatal( 'centralauth-rename-doesnotexist' );
		}

		$oldCentral = CentralAuthUser::getInstanceByName( $formData['oldname'] );
		$canSuppress = $this->getUser() && $this->getUser()->isAllowed( 'centralauth-suppress' );

		if ( ( $oldCentral->isSuppressed() || $oldCentral->isHidden() ) &&
			!$canSuppress
		) {
			return Status::newFatal( 'centralauth-rename-doesnotexist' );
		}

		if ( $oldUser->getName() === $this->getUser()->getName() ) {
			return Status::newFatal( 'centralauth-rename-cannotself' );
		}

		$newUser = $this->userFactory->newFromName( $formData['newname'] );
		if ( !$newUser ) {
			return Status::newFatal( 'centralauth-rename-badusername' );
		}

		return $this->globalRenameUserValidator->validate( $oldUser, $newUser );
	}

	/**
	 * @param array $formData
	 * @return bool|Status
	 */
	public function onSubmit( array $formData ) {
		$out = $this->getOutput();

		$validCentralAuth = $this->validateCentralAuth( $formData );
		if ( !$validCentralAuth->isOK() ) {
			return $validCentralAuth;
		}

		$oldUser = $this->userFactory->newFromName( $formData['oldname'] );
		$newUser = $this->userFactory->newFromName( $formData['newname'], UserFactory::RIGOR_CREATABLE );

		if ( !$oldUser || !$newUser ) {
			return Status::newFatal( 'unknown-error' );
		}

		$globalRenameUser = new GlobalRenameUser(
			$this->getUser(),
			$oldUser,
			CentralAuthUser::getInstance( $oldUser ),
			$newUser,
			CentralAuthUser::getInstance( $newUser ),
			new GlobalRenameUserStatus( $newUser->getName() ),
			$this->jobQueueGroupFactory,
			new GlobalRenameUserDatabaseUpdates( $this->centralAuthDatabaseManager ),
			new GlobalRenameUserLogger( $this->getUser() ),
			$this->centralAuthAntiSpoofManager
		);

		$globalRenameUser->rename(
			array_merge( [
				'movepages' => true,
				'suppressredirects' => false,
				'reason' => $formData['reason'] ?? null,
				'force' => true
			], $formData )
		);

		$logEntry = new ManualLogEntry( 'vanishuser', 'action' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( SpecialPage::getTitleValueFor( 'VanishUser' ) );
		$logID = $logEntry->insert();
		$logEntry->publish( $logID );

		$globalUser = CentralAuthUser::getInstance( $newUser );

		$globalUser->adminLockHide(
			true,
			null,
			'Self-requested vanish',
			$this->getContext(),
			true
		);

		return true;
	}

	public function onSuccess() {
		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'removepii-success' )->escaped() ) );

		$this->getOutput()->addReturnTo(
			SpecialPage::getTitleValueFor( 'VanishUser' ),
			[],
			$this->msg( 'vanishuser' )->text()
		);
	}

	/**
	 * @return bool
	 */
	public function doesWrites() {
		return true;
	}

	/**
	 * @return bool
	 */
	public function isListed() {
		return $this->userCanExecute( $this->getUser() );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'wikimanage';
	}

	/**
	 * @return string
	 */
	protected function getDisplayFormat() {
		return 'ooui';
	}
}
