<?php

namespace Miraheze\MirahezeMagic\Specials;

use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\User\UserOptionsLookup;
use stdClass;
use Wikimedia\Rdbms\DBConnRef;
use Wikimedia\Rdbms\ILBFactory;

class SpecialMirahezeSurvey extends FormSpecialPage {

	/** @var Config */
	private $config;

	/** @var ILBFactory */
	private $dbLoadBalancerFactory;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/** @var DBConnRef */
	private $dbw;

	/** @var stdClass|bool */
	private $row;

	public function __construct(
		ConfigFactory $configFactory,
		ILBFactory $dbLoadBalancerFactory,
		UserOptionsLookup $userOptionsLookup
	) {
		parent::__construct( 'MirahezeSurvey' );

		$this->dbLoadBalancerFactory = $dbLoadBalancerFactory;
		$this->userOptionsLookup = $userOptionsLookup;

		$this->config = $configFactory->makeConfig( 'MirahezeMagic' );
	}

	public function execute( $par ) {
		$out = $this->getOutput();

		$this->setParameter( $par );
		$this->setHeaders();

		if ( !$this->config->get( 'MirahezeSurveyEnabled' ) ) {
			return $out->addHTML( Html::errorBox( $this->msg( 'miraheze-survey-disabled' )->parse() ) );
		}

		$this->dbw = $this->dbLoadBalancerFactory->getMainLB( 'survey' )
			->getConnection( DB_PRIMARY, [], 'survey' );

		$this->row = $this->dbw->selectRow(
			'survey',
			'*', [
				's_id' => md5( $this->getUser()->getName() )
			]
		);

		if ( !$this->row ) {
			$this->dbw->insert(
				'survey', [
					's_id' => md5( $this->getUser()->getName() ),
					's_state' => 'viewed'
				]
			);
		}

		$out->addWikiMsg( 'miraheze-survey-header' );

		$out->addModules( [ 'ext.createwiki.oouiform' ] );

		$out->addModuleStyles( [ 'ext.createwiki.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	protected function getFormFields() {
		$dbRow = json_decode( $this->row->s_data ?? '[]', true );

		$categoryOptions = $this->config->get( 'CreateWikiCategories' );

		unset( $categoryOptions[ array_search( 'uncategorised', $categoryOptions ) ] );

		$yesNoOptions = [
			'miraheze-survey-yes' => 1,
			'miraheze-survey-no' => 0
		];

		$accessOptions = [
			'miraheze-survey-access-sd' => 'severaldaily',
			'miraheze-survey-access-d' => 'daily',
			'miraheze-survey-access-sw' => 'severalweekly',
			'miraheze-survey-access-w' => 'weekly',
			'miraheze-survey-access-sm' => 'severalmonthly',
			'miraheze-survey-access-m' => 'monthly',
			'miraheze-survey-access-lm' => 'lessmothly',
			'miraheze-survey-access-ft' => 'firsttime'
		];

		$formDescriptor = [
			'q1' => [
				'type' => 'select',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q1',
				'options-messages' => [
					'miraheze-survey-q1-anon-read' => 'anon-read',
					'miraheze-survey-q1-anon-edit' => 'anon-edit',
					'miraheze-survey-q1-account-read' => 'account-read',
					'miraheze-survey-q1-account-edit' => 'account-edit',
					'miraheze-survey-q1-account-manage' => 'account-manage'
				],
				'default' => $dbRow['q1'] ?? false
			],
			'q2' => [
				'type' => 'text',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q2',
				'default' => $dbRow['q2'] ?? false,
				'hide-if' => [ 'NOR', [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'anon-edit' ] ]
			],
			'q3a' => [
				'type' => 'select',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q3a',
				'options-messages' => $accessOptions,
				'default' => $dbRow['q3a'] ?? false,
				'hide-if' => [ 'NOR', [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'account-read' ] ]
			],
			'q3b' => [
				'type' => 'select',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q3b',
				'options-messages' => $accessOptions,
				'default' => $dbRow['3b'] ?? false,
				'hide-if' => [ 'NOR', [ '===', 'wpq1', 'anon-edit' ], [ '===', 'wpq1', 'account-edit' ], [ '===', 'wpq1', 'account-manage' ] ]
			],
			'q4a' => [
				'type' => 'select',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q4a',
				'options' => $categoryOptions,
				'default' => $dbRow['q4a'] ?? false,
				'hide-if' => [ 'NOR', [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'account-read' ] ]
			],
			'q4b' => [
				'type' => 'select',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q4b',
				'options' => $categoryOptions,
				'default' => $dbRow['q4b'] ?? false,
				'hide-if' => [ 'NOR', [ '===', 'wpq1', 'anon-edit' ], [ '===', 'wpq1', 'account-edit' ], [ '===', 'wpq1', 'account-manage' ] ]
			],
			'q5a' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q5a',
				'default' => $dbRow['q5a'] ?? 0,
				'min' => 0,
				'hide-if' => [ 'NOR', [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'account-read' ] ]
			],
			'q5b' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q5b',
				'default' => $dbRow['q5b'] ?? 0,
				'min' => 0,
				'hide-if' => [ 'NOR', [ '===', 'wpq1', 'anon-edit' ], [ '===', 'wpq1', 'account-edit' ], [ '===', 'wpq1', 'account-manage' ] ]
			],
			'skin' => [
				'type' => 'hidden',
				'default' => $this->userOptionsLookup->getOption( $this->getUser(), 'skin', 'vector' )
			],
			'q6' => [
				'type' => 'radio',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q6',
				'options-messages' => $yesNoOptions,
				'default' => $dbRow['q6'] ?? false
			],
			'q6-1' => [
				'type' => 'text',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q6-1',
				'default' => $dbRow['q6-1'] ?? false,
				'hide-if' => [ '===', 'wpq6', '1' ]
			],
			'q7' => [
				'type' => 'info',
				'cssclass' => 'createwiki-infuse',
				'default' => $this->msg( 'miraheze-survey-q7' )->text()
			],
			'q7-ci' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-ci',
				'default' => $dbRow['q7-ci'] ?? false
			],
			'q7-si' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-si',
				'default' => $dbRow['q7-si'] ?? false
			],
			'q7-st' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-st',
				'default' => $dbRow['q7-st'] ?? false
			],
			'q7-up' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-up',
				'default' => $dbRow['q7-up'] ?? false
			],
			'q7-speed' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-speed',
				'default' => $dbRow['q7-speed'] ?? false
			],
			'q7-oe' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-oe',
				'default' => $dbRow['q7-oe'] ?? false
			],
			'q7-wc' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-wc',
				'default' => $dbRow['q7-wc'] ?? false,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q7-tasks' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-tasks',
				'default' => $dbRow['q7-tasks'] ?? false,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q7-reqhelp' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q7-reqhelp',
				'default' => $dbRow['q7-reqhelp'] ?? false,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q8' => [
				'type' => 'radio',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q8',
				'options' => $yesNoOptions,
				'default' => $dbRow['q8'] ?? 0,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q8-1' => [
				'type' => 'info',
				'cssclass' => 'createwiki-infuse',
				'default' => $this->msg( 'miraheze-survey-q7' )->text(),
				'hide-if' => [ '!==', 'wpq8', '1' ]
			],
			'q8-e' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q8-e',
				'default' => $dbRow['q8-e'] ?? false,
				'hide-if' => [ '!==', 'wpq8', '1' ]
			],
			'q8-f' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q8-f',
				'default' => $dbRow['q8-f'] ?? false,
				'hide-if' => [ '!==', 'wpq8', '1' ]
			],
			'q8-c' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q8-c',
				'default' => $dbRow['q8-c'] ?? false,
				'hide-if' => [ '!==', 'wpq8', '1' ]
			],
			'q8-u' => [
				'type' => 'int',
				'cssclass' => 'createwiki-infuse',
				'min' => 1,
				'max' => 5,
				'label-message' => 'miraheze-survey-q8-u',
				'default' => $dbRow['q8-u'] ?? false,
				'hide-if' => [ '!==', 'wpq8', '1' ]
			],
			'q9' => [
				'type' => 'text',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q9',
				'default' => $dbRow['q9'] ?? false,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q11' => [
				'type' => 'info',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11'
			],
			'q11-d' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-d',
				'default' => $dbRow['q11-d'] ?? false
			],
			'q11-fd' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-fd',
				'default' => $dbRow['q11-fd'] ?? false
			],
			'q11-s' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-s',
				'default' => $dbRow['q11-s'] ?? false
			],
			'q11-v' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-v',
				'default' => $dbRow['q11-v'] ?? false
			],
			'q11-p' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-p',
				'default' => $dbRow['q11-p'] ?? false
			],
			'q11-f' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-f',
				'default' => $dbRow['q11-f'] ?? false
			],
			'q11-mm' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-mm',
				'default' => $dbRow['q11-mm'] ?? false
			],
			'q11-c' => [
				'type' => 'check',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q11-c',
				'default' => $dbRow['q11-c'] ?? false
			],
			'q12' => [
				'type' => 'textarea',
				'cssclass' => 'createwiki-infuse',
				'rows' => 3,
				'label-message' => 'miraheze-survey-q12',
				'default' => $dbRow['q12'] ?? false
			],
			'q13' => [
				'type' => 'textarea',
				'cssclass' => 'createwiki-infuse',
				'rows' => 3,
				'label-message' => 'miraheze-survey-q13',
				'default' => $dbRow['q13'] ?? false
			],
			'q14' => [
				'type' => 'textarea',
				'cssclass' => 'createwiki-infuse',
				'rows' => 3,
				'label-message' => 'miraheze-survey-q14',
				'default' => $dbRow['q14'] ?? false
			],
			'contact' => [
				'type' => 'radio',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q15',
				'options-messages' => $yesNoOptions,
				'default' => $dbRow['contact'] ?? 0
			]
		];

		if ( $this->getUser()->canReceiveEmail() ) {
			$formDescriptor['email'] = [
				'type' => 'hidden',
				'default' => $this->getUser()->getEmail()
			];
		} else {
			$formDescriptor['email'] = [
				'type' => 'email',
				'cssclass' => 'createwiki-infuse',
				'label-message' => 'miraheze-survey-q15-1',
				'default' => $this->row->s_email ?? '',
				'hide-if' => [ '===', 'wpcontact', '0' ]
			];
		}

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
		if ( !$this->config->get( 'MirahezeSurveyEnabled' ) ) {
			return false;
		}

		$email = $formData['email'];
		unset( $formData['email'] );

		$rows = [
			's_state' => 'completed',
			's_data' => json_encode( $formData ),
			's_email' => $email
		];

		$this->dbw->update(
			'survey',
			$rows,
			[
				's_id' => md5( $this->getUser()->getName() )
			]
		);

		$this->getOutput()->addHTML( Html::successBox( $this->msg( 'miraheze-survey-done' )->escaped() ) );

		return true;
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}
}
