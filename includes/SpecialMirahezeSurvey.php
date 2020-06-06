<?php

class SpecialMirahezeSurvey extends FormSpecialPage {
	private $dbw;
	private $row;

	public function __construct() {
		parent::__construct( 'MirahezeSurvey' );
		$this->dbw = wfGetDB( DB_MASTER, [], 'survey' );
	}

        public function execute( $par ) {
                $request = $this->getRequest();
                $out = $this->getOutput();
                $this->setParameter( $par );
                $this->setHeaders();

		$this->row = $this->dbw->selectRow(
			'survey',
			'*',
			[
				's_id' => md5( $this->getUser()->getName() )
			]
		);

		if ( !$this->row ) {
			$this->dbw->insert(
				'survey',
				[
					's_id' => md5( $this->getUser()->getName() ),
					's_state' => 'viewed'
				]
			);
		}

		$out->addWikiMsg( 'miraheze-survey-header' );

                $form = $this->getForm();
                if ( $form->show() ) {
                        $this->onSuccess();
                }
        }

	protected function getFormFields() {
		global $wgCreateWikiCategories;

		$dbRow = json_decode( $this->row->s_data, true );

		$categoryOptions = $wgCreateWikiCategories;
		unset( $categoryOptions[array_search( 'uncategorised', $categoryOptions )] );

		$yesNoOptions = [
			$this->msg( 'miraheze-survey-yes' )->text() => 1,
			$this->msg( 'miraheze-survey-no' )->text() => 0
		];

		$accessOptions = [
			$this->msg( 'miraheze-survey-access-sd' )->text() => 'severaldaily',
			$this->msg( 'miraheze-survey-access-d' )->text() => 'daily',
			$this->msg( 'miraheze-survey-access-sw' )->text() => 'severalweekly',
			$this->msg( 'miraheze-survey-access-w' )->text() => 'weekly',
			$this->msg( 'miraheze-survey-access-sm' )->text() => 'severalmonthly',
			$this->msg( 'miraheze-survey-access-m' )->text() => 'monthly',
			$this->msg( 'miraheze-survey-access-lm' )->text() => 'lessmothly',
			$this->msg( 'miraheze-survey-access-ft' )->text() => 'firsttime'
		];

		$agreeDisColumns = [
			$this->msg( 'miraheze-survey-agree-strong' )->text() => 'sa',
			$this->msg( 'miraheze-survey-agree' )->text() => 'a',
			$this->msg( 'miraheze-survey-neutral' )->text() => 'n',
			$this->msg( 'miraheze-survey-disagree' )->text() => 'd',
			$this->msg( 'miraheze-survey-disagree-strong' )->text() => 'sd'
		];

		$formDescriptor = [
			'q1' => [
				'type' => 'select',
				'label-message' => 'miraheze-survey-q1',
				'options' => [
					$this->msg( 'miraheze-survey-q1-anon-read' )->text() => 'anon-read',
					$this->msg( 'miraheze-survey-q1-anon-edit' )->text() => 'anon-edit',
					$this->msg( 'miraheze-survey-q1-account-read' )->text() => 'account-read',
					$this->msg( 'miraheze-survey-q1-account-edit' )->text() => 'account-edit',
					$this->msg( 'miraheze-survey-q1-account-manage' )->text() => 'account-manage'
				],
				'default' => $dbRow['q1'] ?? false
			],
			'q2' => [
				'type' => 'text',
				'label-message' => 'miraheze-survey-q2',
				'default' => $dbRow['q2'] ?? false,
				'hide-if' => [ 'NOR',  [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'anon-edit' ] ]
			],
			'q3a' => [
				'type' => 'select',
				'label-message' => 'miraheze-survey-q3a',
				'options' => $accessOptions,
				'default' => $dbRow['q3a'] ?? false,
				'hide-if' => [ 'NOR',  [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'account-read' ] ]
			],
			'q3b' => [
				'type' => 'select',
				'label-message' => 'miraheze-survey-q3b',
				'options' => $accessOptions,
				'default' => $dbRow['3b'] ?? false,
				'hide-if' => [ 'NOR',  [ '===', 'wpq1', 'anon-edit' ], [ '===', 'wpq1', 'account-edit' ], [ '===', 'wpq1', 'account-manage' ] ]
			],
			'q4a' => [
				'type' => 'select',
				'label-message' => 'miraheze-survey-q4a',
				'options' => $categoryOptions,
				'default' => $dbRow['q4a'] ?? false,
				'hide-if' => [ 'NOR',  [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'account-read' ] ]
			],
			'q4b' => [
				'type' => 'select',
				'label-message' => 'miraheze-survey-q4b',
				'options' => $categoryOptions,
				'default' => $dbRow['q4b'] ?? false,
				'hide-if' => [ 'NOR',  [ '===', 'wpq1', 'anon-edit' ], [ '===', 'wpq1', 'account-edit' ], [ '===', 'wpq1', 'account-manage' ] ]
			],
			'q5a' => [
				'type' => 'int',
				'label-message' => 'miraheze-survey-q5a',
				'default' => $dbRow['q5a'] ?? 0,
				'hide-if' => [ 'NOR',  [ '===', 'wpq1', 'anon-read' ], [ '===', 'wpq1', 'account-read' ] ]
			],
			'q5b' => [
				'type' => 'int',
				'label-message' => 'miraheze-survey-q5b',
				'default' => $dbRow['q5b'] ?? 0,
				'hide-if' => [ 'NOR',  [ '===', 'wpq1', 'anon-edit' ], [ '===', 'wpq1', 'account-edit' ], [ '===', 'wpq1', 'account-manage' ] ]
			],
			'skin' => [
				'type' => 'hidden',
				'default' => $this->getUser()->getOption( 'skin' )
			],
			'q6' => [
				'type' => 'radio',
				'label-message' => 'miraheze-survey-q6',
				'options' => $yesNoOptions,
				'default' => $dbRow['q6'] ?? false
			],
			'q6-1' => [
				'type' => 'text',
				'label-message' => 'miraheze-survey-q6-1',
				'default' => $dbRow['q6-1'] ?? false,
				'hide-if' => [ '===', 'wpq6', '1' ]
			],
			'q7' => [
				'type' => 'checkmatrix',
				'label-message' => 'miraheze-survey-q7',
				'columns' => $agreeDisColumns,
				'rows' => [
					$this->msg( 'miraheze-survey-q7-ci' )->text() => 'ci',
					$this->msg( 'miraheze-survey-q7-si' )->text() => 'si',
					$this->msg( 'miraheze-survey-q7-up' )->text() => 'up',
					$this->msg( 'miraheze-survey-q7-speed' )->text() => 'speed',
					$this->msg( 'miraheze-survey-q7-oe' )->text() => 'oe'
				],
				'default' => $dbRow['q7'] ?? false,
				'hide-if' => [ '===', 'wpq1', 'account-manage' ]
			],
			'q8' => [
				'type' => 'radio',
				'label-message' => 'miraheze-survey-q8',
				'options' => $yesNoOptions,
				'default' => $dbRow['q8'] ?? 0,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q8-1' => [
				'type' => 'checkmatrix',
				'label-message' => 'miraheze-survey-q7',
				'columns' => $agreeDisColumns,
				'rows' => [
					$this->msg( 'miraheze-survey-q8-e' )->text() => 'e',
					$this->msg( 'miraheze-survey-q8-f' )->text() => 'f',
					$this->msg( 'miraheze-survey-q8-c' )->text() => 'c',
					$this->msg( 'miraheze-survey-q8-u' )->text() => 'u'
				],
				'default' => $dbRow['q8-1'] ?? false,
				'hide-if' => [ '!==', 'wpq8', '1' ]
			],
			'q9' => [
				'type' => 'text',
				'label-message' => 'miraheze-survey-q9',
				'default' => $dbRow['q9'] ?? false,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q10' => [
				'type' => 'checkmatrix',
				'label-message' => 'miraheze-survey-q7',
				'columns' => $agreeDisColumns,
				'rows' => [
					$this->msg( 'miraheze-survey-q7-wc' )->text() => 'wc',
					$this->msg( 'miraheze-survey-q7-tasks' )->text() => 'tasks',
					$this->msg( 'miraheze-survey-q7-ci' )->text() => 'ci',
					$this->msg( 'miraheze-survey-q7-si' )->text() => 'si',
					$this->msg( 'miraheze-survey-q7-up' )->text() => 'up',
					$this->msg( 'miraheze-survey-q7-speed' )->text() => 'speed',
					$this->msg( 'miraheze-survey-q7-oe' )->text() => 'oe'
				],
				'default' => $dbRow['q10'] ?? false,
				'hide-if' => [ '!==', 'wpq1', 'account-manage' ]
			],
			'q11' => [
				'type' => 'checkmatrix',
				'label-message' => 'miraheze-survey-q11',
				'columns' => $yesNoOptions,
				'rows' => [
					$this->msg( 'miraheze-survey-q11-d' )->text() => 'd',
					$this->msg( 'miraheze-survey-q11-s' )->text() => 's',
					$this->msg( 'miraheze-survey-q11-v' )->text() => 'v',
					$this->msg( 'miraheze-survey-q11-p' )->text() => 'p',
					$this->msg( 'miraheze-survey-q11-f' )->text() => 'f'
				],
				'default' => $dbRow['q11'] ?? false
			],
			'q12' => [
				'type' => 'text',
				'label-message' => 'miraheze-survey-q12',
				'default' => $dbRow['q12'] ?? false
			],
			'q13' => [
				'type' => 'text',
				'label-message' => 'miraheze-survey-q13',
				'default' => $dbRow['q13'] ?? false
			],
			'q14' => [
				'type' => 'text',
				'label-message' => 'miraheze-survey-q14',
				'default' => $dbRow['q14'] ?? false
			],
			'contact' => [
				'type' => 'radio',
				'label-message' => 'miraheze-survey-q15',
				'options' => $yesNoOptions,
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
				'label-message' => 'miraheze-survey-q15-1',
				'default' => $row->s_email ?? '',
				'hide-if' => [ '===', 'wpcontact', '0' ]
			];
		}

		return $formDescriptor;
	}

	public function onSubmit( array $formData ) {
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

		$this->getOutput()->addHTML( '<div class="successbox">' . $this->msg( 'miraheze-survey-done' )->plain() . '</div>' );

		return true;
	}

	protected function getDisplayFormat() {
		return 'ooui';
	}
}
