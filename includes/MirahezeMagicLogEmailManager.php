<?php

use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;

class MirahezeMagicLogEmailManager {
	/** @var Config */
	private $config;

	/**
	 * @param Config $config
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @return array[] in format of [ 'group' => string, 'email' => string ]
	 */
	private function getLogConditions(): array {
		return $this->config->get( 'MirahezeMagicLogEmailConditions' );
	}

	/**
	 * Find all matching log email conditions for the rights the specified user has.
	 * @param User $user
	 * @return array
	 */
	public function findForUser( User $user ): array {
		if ( !$user->isRegistered() ) {
			return [];
		}

		$groups = CentralAuthUser::getInstance( $user )->getGlobalGroups();

		$found = [];
		foreach ( $this->getLogConditions() as $condition ) {
			if ( in_array( $condition['group'], $groups ) ) {
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
