<?php

use MediaWiki\MediaWikiServices;
use OOUI\LabelWidget;
use OOUI\PanelLayout;
use OOUI\PanelWidget;
use OOUI\Tag;
use Wikimedia\Rdbms\IDatabase;

class SpecialMirahezeSurveyResults extends SpecialPage {

	/** @var Config */
	private $config;

	/** @var IDatabase */
	private $dbr;

	/** @var array */
	private $results;

	public function __construct( ConfigFactory $configFactory ) {
		parent::__construct( 'MirahezeSurveyResults', 'viewmirahezesurveyresults' );

		$this->config = $configFactory->makeConfig( 'mirahezemagic' );
	}

	public function execute( $par ) {
		$out = $this->getOutput();

		$this->setHeaders();

		if ( !$this->config->get( 'MirahezeSurveyEnabled' ) ) {
			return $out->addHTML( Html::errorBox( $this->msg( 'miraheze-survey-disabled' )->parse() ) );
		}

		$this->dbr = MediaWikiServices::getInstance()->getDBLoadBalancerFactory()
			->getMainLB( 'survey' )
			->getConnection( DB_REPLICA, [], 'survey' );

		$this->results = $this->getSurveyResults();

		$out->addHtml( $this->getSurveyResultsTable() );
	}

	private function getSurveyResults() {
		$results = [];
		$res = $this->dbr->select(
			'survey',
			'*',
			[],
			__METHOD__
		);

		foreach ( $res as $row ) {
			$data = json_decode( $row->s_data ?? '[]', true );
			$results[] = [
				'user_id' => $row->s_id,
				'q1' => $data['q1'] ?? '',
				'q2' => $data['q2'] ?? '',
				'q3a' => $data['q3a'] ?? '',
				'q3b' => $data['q3b'] ?? '',
				'q4a' => $data['q4a'] ?? '',
				'q4b' => $data['q4b'] ?? '',
				'q5a' => $data['q5a'] ?? '',
				'q5b' => $data['q5b'] ?? '',
				'q6' => $data['q6'] ?? '',
				'q6-1' => $data['q6-1'] ?? '',
				'q7-ci' => $data['q7-ci'] ?? '',
				'q7-si' => $data['q7-si'] ?? '',
				'q7-st' => $data['q7-st'] ?? '',
				'q7-up' => $data['q7-up'] ?? '',
				'q7-speed' => $data['q7-speed'] ?? '',
				'q7-oe' => $data['q7-oe'] ?? '',
				'q7-wc' => $data['q7-wc'] ?? '',
				'q7-tasks' => $data['q7-tasks'] ?? '',
				'q7-reqhelp' => $data['q7-reqhelp'] ?? '',
				'q8' => $data['q8'] ?? '',
				'q8-e' => $data['q8-e'] ?? '',
				'q8-f' => $data['q8-f'] ?? '',
				'q9' => $data['q9'] ?? '',
				'q10' => $data['q10'] ?? '',
				'q11' => $data['q11'] ?? '',
				'q12' => $data['q12'] ?? '',
				'q13' => $data['q13'] ?? '',
				'q14' => $data['q14'] ?? '',
			];
		}

		return $results;
	}

	private function getSurveyResultsTable() {
		$table = new PanelLayout( [
			'classes' => [ 'mw-special-SpecialMirahezeSurveyResults' ],
		] );

		$table->appendContent( new LabelWidget( [
			'label' => $this->msg( 'miraheze-survey-results-heading' )->escaped(),
			'classes' => [ 'mw-special-SpecialMirahezeSurveyResults-heading' ],
		] ) );

		$panel = new PanelWidget( [
			'expanded' => false,
			'padded' => true,
		] );

		$headerRow = [
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-user-id' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q1' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q2' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q3a' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q3b' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q4a' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q4b' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q5a' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q5b' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q6' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q6-1' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-ci' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-si' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-st' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-up' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-speed' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-oe' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-wc' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-tasks' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q7-reqhelp' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q8' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q8-e' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q8-f' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q9' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q10' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q11' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q12' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q13' )->escaped() ),
			new Tag( 'th', [], $this->msg( 'miraheze-survey-results-heading-q14' )->escaped() ),
		] );

		$bodyRows = [];

		foreach ( $this->results as $result ) {
			$bodyRows[] = [
				new Tag( 'td', [], $result['user_id'] ),
				new Tag( 'td', [], $result['q1'] ),
				new Tag( 'td', [], $result['q2'] ),
				new Tag( 'td', [], $result['q3a'] ),
				new Tag( 'td', [], $result['q3b'] ),
				new Tag( 'td', [], $result['q4a'] ),
				new Tag( 'td', [], $result['q4b'] ),
				new Tag( 'td', [], $result['q5a'] ),
				new Tag( 'td', [], $result['q5b'] ),
				new Tag( 'td', [], $result['q6'] ),
				new Tag( 'td', [], $result['q6-1'] ),
				new Tag( 'td', [], $result['q7-ci'] ),
				new Tag( 'td', [], $result['q7-si'] ),
				new Tag( 'td', [], $result['q7-st'] ),
				new Tag( 'td', [], $result['q7-up'] ),
				new Tag( 'td', [], $result['q7-speed'] ),
				new Tag( 'td', [], $result['q7-oe'] ),
				new Tag( 'td', [], $result['q7-wc'] ),
				new Tag( 'td', [], $result['q7-tasks'] ),
				new Tag( 'td', [], $result['q7-reqhelp'] ),
				new Tag( 'td', [], $result['q8'] ),
				new Tag( 'td', [], $result['q8-e'] ),
				new Tag( 'td', [], $result['q8-f'] ),
				new Tag( 'td', [], $result['q9'] ),
				new Tag( 'td', [], $result['q10'] ),
				new Tag( 'td', [], $result['q11'] ),
				new Tag( 'td', [], $result['q12'] ),
				new Tag( 'td', [], $result['q13'] ),
				new Tag( 'td', [], $result['q14'] ),
			];
		}

		$table->appendContent(
			new PanelWidget( [
				'classes' => [ 'mw-special-SpecialMirahezeSurveyResults-table-wrapper' ],
				'content' => new Tag( 'table', [
					'class' => 'wikitable mw-special-SpecialMirahezeSurveyResults-table'
				], [
					new Tag( 'thead', [], [
						new Tag( 'tr', [], $headerRow )
					] ),
					new Tag( 'tbody', [], array_map( static function ( $row ) {
						return new Tag( 'tr', [], $row );
					}, $bodyRows ) )
				] )
			] )
		);

		return $table;
	}
}
