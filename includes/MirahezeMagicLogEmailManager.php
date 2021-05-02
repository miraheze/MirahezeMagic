<?php

use MediaWiki\Permissions\PermissionManager;

class MirahezeMagicLogEmailManager {
	/** @var Config */
	private $config;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param Config $config
	 * @param PermissionManager $permissionManager
	 */
	public function __construct( Config $config, PermissionManager $permissionManager ) {
		$this->config = $config;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @return array[] in format of [ 'right' => string, 'email' => string ]
	 */
	private function getLogConditions() : array {
		return $this->config->get( 'MirahezeMagicLogEmailConditions' );
	}

	/**
	 * Find all matching log email conditions for the rights the specified user has.
	 * @param User $user
	 * @return array
	 */
	public function findForUser( User $user ) : array {
		$rights = $this->permissionManager->getUserPermissions( $user );

		$found = [];
		foreach ( $this->getLogConditions() as $condition ) {
			if ( in_array( $condition['right'], $rights ) ) {
				$found[] = $condition;
			}
		}

		return $found;
	}

	/**
	 * @param array $data Array with keys 'user_name', 'wiki_id', 'log_type', 'comment_text'
	 * @param string $email Email to send to
	 */
	public function sendEmail( array $data, string $email ) {
		DeferredUpdates::addCallableUpdate( function () use ( $data, $email ) {
			$adminAddress = new MailAddress( $this->config->get( 'PasswordSender' ),
				wfMessage( 'emailsender' )->inContentLanguage()->text() );

			UserMailer::send(
				new MailAddress( $email ),
				$adminAddress,
				'User action notification',
				json_encode( $data )
			);
		} );
	}
}
