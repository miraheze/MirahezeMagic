<?php

/**
 * Provides information for rendering the ReCaptcha HTML based on the provided data.
 */
class ReCaptchaHtml {
	/** @var string */
	private $siteKey;
	/** @var string */
	private $languageCode;
	/** @var string */
	private $error;
	/** @var string Either v2 or v3 */
	private $version;
	/** @var string */
	private $action;

	public function __construct( $siteKey, $languageCode, $error, $version, $action ) {
		$this->siteKey = $siteKey;
		$this->languageCode = htmlspecialchars( urlencode( $languageCode ) );
		$this->error = $error;
		$this->version = $version;
		$this->action = $action;
	}

	/**
	 * Returns an array with the information needed to add the ReCaptcha related header item. The
	 * first element of the array is a name of the head item, which might be useful if passing it
	 * to OutputPage::addHeadItem, the second one if the content of the head item.
	 *
	 * @return array
	 */
	public function headItem() {
		if ( $this->version === 'v3' ) {
			$script =
				"<script src=\"https://www.recaptcha.net/recaptcha/api.js?render=$this->siteKey\"></script>" .
				$this->v3Script();
		} else {
			// Insert reCAPTCHA script, in display language, if available.
			// Language falls back to the browser's display language.
			// See https://developers.google.com/recaptcha/docs/faq
			$script =
				"<script src=\"https://www.recaptcha.net/recaptcha/api.js?hl={$this->languageCode}\"" .
				"async defer></script>";
		}

		return [
			'g-recaptchascript',
			$script,
		];
	}

	/**
	 * @return string The Content which should be added to the visible output of the page (e.g.
	 * the body tag).
	 */
	public function render() {
		$output = Html::element( 'div', [
			'class' => [
				'g-recaptcha' => ( $this->version !== 'v3' ),
				'mw-confirmedit-captcha-fail' => (bool)$this->error,
			],
			'data-sitekey' => $this->siteKey,
		] );
		$htmlUrlencoded = htmlspecialchars( urlencode( $this->siteKey ) );

		if ( $this->version === 'v3' ) {
			return $output . <<<HTML
<noscript>Please enable JavaScript in order to verify, that you're a human.</noscript>
<input type="hidden" name="g-recaptcha-response" id="reCaptchaField">
HTML;

		}

		return $output . <<<HTML
<noscript>
  <div>
    <div style="width: 302px; height: 422px; position: relative;">
      <div style="width: 302px; height: 422px; position: absolute;">
        <iframe src=
        "https://www.recaptcha.net/recaptcha/api/fallback?k={$htmlUrlencoded}&hl={$this->languageCode}"
        frameborder="0" scrolling="no" style="width: 302px;height:422px; border-style: none;">
        </iframe>
      </div>
    </div>
    <div style="width: 300px; height: 60px; border-style: none;
                bottom: 12px; left: 25px; margin: 0px; padding: 0px; right: 25px;
                background: #f9f9f9; border: 1px solid #c1c1c1; border-radius: 3px;">
      <textarea id="g-recaptcha-response" name="g-recaptcha-response"
                class="g-recaptcha-response"
                style="width: 250px; height: 40px; border: 1px solid #c1c1c1;
                       margin: 10px 25px; padding: 0px; resize: none;" >
      </textarea>
    </div>
  </div>
</noscript>
HTML;
	}

	private function v3Script() {
		$script = <<<HTML
<script>
grecaptcha.ready(function() {
    grecaptcha.execute('$this->siteKey', {action: '$this->action'}).then(function(token) {
       var reCaptchaField = document.getElementById('reCaptchaField');
       reCaptchaField.value = token;
    });
});
</script>
HTML;

		return preg_replace( '/\s+/', '', $script );
	}
}
