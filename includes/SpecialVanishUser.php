<?php
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

use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserFactory;

class SpecialVanishUser extends FormSpecialPage {

	/** @var JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		JobQueueGroupFactory $jobQueueGroupFactory,
		UserFactory $userFactory
	) {
		parent::__construct( 'VanishUser', 'centralauth-rename' );

		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->userFactory = $userFactory;
	}

	/**
	 * @param string $par
	 * @return string
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
			'class' => MediaWiki\Extension\CentralAuth\Widget\HTMLGlobalUserTextField::class,
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

		if ( version_compare( MW_VERSION, '1.40', '<' ) &&
			!ExtensionRegistry::getInstance()->isLoaded( 'Renameuser' )
		) {
			return Status::newFatal( 'centralauth-rename-notinstalled' );
		}

		$oldUser = $this->userFactory->newFromName( $formData['oldname'] );
		if ( !$oldUser ) {
			return Status::newFatal( 'centralauth-rename-doesnotexist' );
		}

		$oldCentral = MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstanceByName( $formData['oldname'] );
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

		$globalRenameUserValidator = MediaWikiServices::getInstance()->getService(
			'CentralAuth.GlobalRenameUserValidator'
		);
		return $globalRenameUserValidator->validate( $oldUser, $newUser );
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

		$caDbManager = MediaWikiServices::getInstance()->getService(
			'CentralAuth.CentralAuthDatabaseManager'
		);

		$oldUser = $this->userFactory->newFromName( $formData['oldname'] );
		$newUser = $this->userFactory->newFromName( $formData['newname'], UserFactory::RIGOR_CREATABLE );

		if ( !$oldUser || !$newUser ) {
			return Status::newFatal( 'unknown-error' );
		}

		$session = $this->getContext()->exportSession();
		$globalRenameUser = new MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUser(
			$this->getUser(),
			$oldUser,
			MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstance( $oldUser ),
			$newUser,
			MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstance( $newUser ),
			new MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserStatus( $newUser->getName() ),
			$this->jobQueueGroupFactory,
			new MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserDatabaseUpdates( $caDbManager ),
			new MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger( $this->getUser() ),
			$session
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

		$globalUser = MediaWiki\Extension\CentralAuth\User\CentralAuthUser::getInstance( $newUser );

		$globalUser->adminLockHide(
			true,
			null,
			'Self-requested vanish',
			$this->getContext(),
			1
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
