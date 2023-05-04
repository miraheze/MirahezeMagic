<?php

namespace Miraheze\MirahezeMagic\Specials;

use ExtensionRegistry;
use FormSpecialPage;
use Html;
use ManualLogEntry;
use MediaWiki\Extension\CentralAuth\CentralAuthDatabaseManager;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUser;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserDatabaseUpdates;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserLogger;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserStatus;
use MediaWiki\Extension\CentralAuth\GlobalRename\GlobalRenameUserValidator;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\Extension\CentralAuth\Widget\HTMLGlobalUserTextField;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\User\UserFactory;
use SpecialPage;
use Status;

// phpcs:enable
class SpecialVanishUser extends FormSpecialPage {
	/** @var CentralAuthDatabaseManager */
	private $centralAuthDatabaseManager;

	/** @var GlobalRenameUserValidator */
	private $globalRenameUserValidator;

	/** @var JobQueueGroupFactory */
	private $jobQueueGroupFactory;

	/** @var UserFactory */
	private $userFactory;

	/**
	 * @param CentralAuthDatabaseManager $centralAuthDatabaseManager
	 * @param GlobalRenameUserValidator $globalRenameUserValidator
	 * @param JobQueueGroupFactory $jobQueueGroupFactory
	 * @param UserFactory $userFactory
	 */
	public function __construct(
		CentralAuthDatabaseManager $centralAuthDatabaseManager,
		GlobalRenameUserValidator $globalRenameUserValidator,
		JobQueueGroupFactory $jobQueueGroupFactory,
		UserFactory $userFactory
	) {
		parent::__construct( 'VanishUser', 'centralauth-rename' );

		$this->centralAuthDatabaseManager = $centralAuthDatabaseManager;
		$this->globalRenameUserValidator = $globalRenameUserValidator;
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

		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Renameuser' ) ) {
			return Status::newFatal( 'centralauth-rename-notinstalled' );
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

		$session = $this->getContext()->exportSession();
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

		$globalUser = CentralAuthUser::getInstance( $newUser );

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
