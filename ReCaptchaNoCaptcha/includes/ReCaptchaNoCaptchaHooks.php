<?php

class ReCaptchaNoCaptchaHooks {
	/**
	 * Messages that should also be showed in the v3 version of ReCaptcha. The message key must
	 * start with an uppercase letter.
	 *
	 * @var array Message keys
	 */
	private static $v3CaptchaMessages = [
		'Renocaptcha-desc',
		'Renocaptcha-addurl',
		'Renocaptcha-badlogin',
		'Renocaptcha-v3-failed'
	];

	/**
	 * Adds extra variables to the global config
	 *
	 * @param array &$vars Global variables object
	 * @return bool Always true
	 */
	public static function onResourceLoaderGetConfigVars( array &$vars ) {
		global $wgReCaptchaSiteKey;
		global $wgCaptchaClass;

		if ( $wgCaptchaClass === 'ReCaptchaNoCaptcha' ) {
			$vars['wgConfirmEditConfig'] = [
				'reCaptchaSiteKey' => $wgReCaptchaSiteKey,
				'reCaptchaScriptURL' => 'https://www.google.com/recaptcha/api.js'
			];
		}

		return true;
	}

	/**
	 * Whenever v3 of ReCaptcha is used, the most messages in the frontend doesn't make any
	 * sense, as the user does not need to solve any CAPTCHA. Disable these messages here, so
	 * that ConfirmEdit does not render them.
	 *
	 * @param string $title The message key
	 * @param string &$message The message to return
	 * @param string $code The language code to load the message for
	 */
	public static function onMessagesPreLoad( $title, &$message, $code ) {
		global $wgReCaptchaVersion;

		if ( $wgReCaptchaVersion === 'v2' ) {
			return;
		}

		if ( in_array( $title, self::$v3CaptchaMessages ) ) {
			return;
		}

		if ( strpos( $title, 'Renocaptcha' ) === 0 ) {
			$message = new RawMessage( '' );
		}
	}
}
