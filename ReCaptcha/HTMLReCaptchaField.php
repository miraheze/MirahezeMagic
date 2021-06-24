<?php

/**
 * Creates a ReCaptcha v2 widget. Does not return any data; handling the data submitted by the
 * widget is callers' responsibility.
 */
class HTMLReCaptchaField extends HTMLFormField {
	/** @var string Public key parameter to be passed to ReCaptcha. */
	protected $key;

	/** @var string Error returned by ReCaptcha in the previous round. */
	protected $error;

	/**
	 * Parameters:
	 * - key: (string, required) ReCaptcha public key
	 * - error: (string) ReCaptcha error from previous round
	 * @param array $params
	 */
	public function __construct( array $params ) {
		$params += [ 'error' => null ];
		parent::__construct( $params );

		$this->key = $params['key'];
		$this->error = $params['error'];

		$this->mName = 'g-recaptcha-response';
	}

	public function getInputHTML( $value ) {
		global $wgReCaptchaVersion;

		$out = $this->mParent->getOutput();
		$captchaHtml = new ReCaptchaHtml(
			$this->key,
			$this->mParent->getLanguage()->getCode(),
			$this->error,
			$wgReCaptchaVersion,
			'authManager'
		);

		call_user_func_array( [ $out, 'addHeadItem' ], $captchaHtml->headItem() );

		return $captchaHtml->render();
	}
}
