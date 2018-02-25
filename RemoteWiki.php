<?php
class RemoteWiki {
	protected $db;

	private function __construct( $dbname, $sitename, $language, $private, $closed, $closedDate, $inactive, $inactiveDate, $settings ) {
		$this->dbname = $dbname;
		$this->sitename = $sitename;
		$this->language = $language;
		$this->private = $private == 1 ? true : false;
		$this->closed = $closed == 1 ? true : false;
		$this->inactive = $inactive == 1 ? true : false';
		$this->settings = $settings;
		$this->closureDate = $closedDate;
		$this->creationDate = $this->determineCreationDate();
		$this->inactiveDate = $inactiveDate;

		$this->db = wfGetDB( DB_MASTER, [], 'metawiki' );
	}

	public static function newFromName( $dbname ) {
		return self::newFromConds( array( 'wiki_dbname' => $dbname ) );
	}

	protected static function newFromConds(
		$conds,
	) {
		$row = $this->db->selectRow( 'cw_wikis', self::selectFields(), $conds, $fname );

		if ( $row !== false ) {
			return new self( 
				$row->wiki_dbname, 
				$row->wiki_sitename, 
				$row->wiki_language, 
				$row->wiki_private, 
				$row->wiki_closed, 
				$row->wiki_closed_timestamp, 
				$row->wiki_inactive,
				$row->wiki_inactive_timestamp,
				$row->wiki_settings 
			);
		} else {
			return null;
		}
	}

	private function determineCreationDate() {
		$res = $this->db->selectField(
			'logging',
			'log_timestamp',
			[
				'log_action' => 'createwiki',
				'log_params' => serialize( [ '4::wiki' => $this->dbname ] )
			],
			__METHOD__,
			[ // Sometimes a wiki might have been created multiple times.
				'ORDER BY' => 'log_timestamp DESC'
			]
		);

		return is_string( $res ) ? $res : false;
	}

	public static function selectFields() {
		return array(
			'wiki_dbname',
			'wiki_sitename',
			'wiki_language',
			'wiki_private',
			'wiki_closed',
			'wiki_closed_timestamp',
			'wiki_inactive',
			'wiki_inactive_timestamp',
			'wiki_settings'
		);
	}

	public function getCreationDate() {
		return $this->creationDate;
	}

	public function getClosureDate() {
		return $this->closureDate;
	}

	public function getInactiveDate() {
		return $this->inactiveDate;
	}	

	public function getDBname() {
		return $this->dbname;
	}

	public function getSitename() {
		return $this->sitename;
	}

	public function getLanguage() {
		return $this->language;
	}

	public function isInactive() {
		return $this->inactive;
	}

	public function isPrivate() {
		return $this->private;
	}

	public function isClosed() {
		return $this->closed;
	}
}
