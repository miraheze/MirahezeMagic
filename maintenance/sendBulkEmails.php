<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 * @ingroup Wikimedia
 */

require_once __DIR__ . '/WikimediaMaintenance.php';

/**
 * Send a bulk email message to a list of wiki account holders using
 * User::sendMail.
 *
 * Features:
 * - Read message body from a file
 * - Read recipients from a file, one per line
 * - Only send to accounts with confirmed addresses
 * - Only send to accounts that have not opted out of email contact
 * - Optional: check users against an opt-out list maintained on-wiki
 * - Optional: set a From: address
 * - Optional: set a Reply-To: address
 *
 * @copyright © 2017 Wikimedia Foundation and contributors.
 */
class SendBulkEmails extends Maintenance {
	/**
	 * @var string $DEFAULT_START Opt-out list start marker
	 */
	const DEFAULT_START = '<!-- BEGIN OPT-OUT LIST -->';

	/**
	 * @var string $DEFAULT_END Opt-out list end marker
	 */
	const DEFAULT_END = '<!-- END OPT-OUT LIST -->';

	/**
	 * @var int $DEFAULT_DELAY Email send delay (seconds)
	 */
	const DEFAULT_DELAY = 5;

	/**
	 * @var int $start Unix epoch time
	 */
	private $start = 0;

	/**
	 * @var int $missing Count of users not found in database
	 */
	private $missing = 0;

	/**
	 * @var int $noreceive Count of users who can not receive email
	 */
	private $noreceive = 0;

	/**
	 * @var int $optedout Count of users listed on the opt-out page
	 */
	private $optedout = 0;

	/**
	 * @var int $failed Count of User::sendMail() failures
	 */
	private $failed = 0;

	/**
	 * @var int $ok Count of User::sendMail() successes
	 */
	private $ok = 0;

	/**
	 * @var int $total Count of users processed
	 */
	private $total = 0;

	/**
	 * @var string $subject Email subject
	 */
	private $subject = '';

	/**
	 * @var string $body Email body
	 */
	private $body = '';

	/**
	 * @var User|null $from Email From: user
	 */
	private $from = null;

	/**
	 * @var MailAddress|null $replyto Email Reoly-To: address
	 */
	private $replyto = null;

	/**
	 * @var string $optoutStart Opt-out list start marker
	 */
	private $optoutStart = self::DEFAULT_START;

	/**
	 * @var string $optoutEnd Opt-out list end marker
	 */
	private $optoutEnd = self::DEFAULT_END;

	/**
	 * @var string[] $optout List of opt-out usernames
	 */
	private $optout = [];

	/**
	 * @var string|null $optoutUrl Full URL to opt-out page
	 */
	private $optoutUrl = null;

	/**
	 * @var int $delpy Number of seconds to delay between email sends
	 */
	private $delay = self::DEFAULT_DELAY;

	/**
	 * @var bool $dryRun Dry run (no email send) guard
	 */
	private $dryRun = false;

	public function __construct() {
		parent::__construct();
		$this->start = microtime( true );
		$this->mDescription =
			'Send bulk email to a list of wiki account holders';
		$this->addOption( 'subject', 'Email subject (string)', true, true );
		$this->addOption( 'body', 'Email body (file)', true, true );
		$this->addOption( 'to',
			'List of users to email, one per line (file)', true, true );
		$this->addOption( 'from', 'Email sender (username)', false, true );
		$this->addOption( 'reply-to',
			'Reply-To address (username)', false, true );
		$this->addOption( 'optout',
			'Wikipage containing list of users to exclude from contact (title)',
			false, true );
		$this->addOption( 'optout-start',
			'Opt-out list start marker', false, true );
		$this->addOption( 'optout-end',
			'Opt-out list end marker', false, true );
		$this->addOption( 'delay',
			'Time to wait between emails (seconds)', false, true );
		$this->addOption( 'dry-run', 'Do not send emails' );
	}

	public function execute() {
		$this->subject = $this->getOption( 'subject' );
		$this->body = $this->getFileContents( 'body' );
		$this->from = $this->getSender();
		$this->replyto = $this->getReplyTo();
		$this->optoutStart = $this->getOption(
			'optout-start', self::DEFAULT_START );
		$this->optoutEnd = $this->getOption( 'optout-end', self::DEFAULT_END );
		$this->optout = $this->getOptOutList();
		$this->delay = $this->getOption( 'delay', self::DEFAULT_DELAY );
		$this->dryRun = $this->hasOption( 'dry-run' );

		Hooks::register(
			'UserMailerTransformMessage',
			[ $this, 'onUserMailerTransformMessage' ]
		);

		$to = $this->getFileHandle( 'to' );
		for (
			$username = trim( fgets( $to ) );
			strlen( $username );
			$username = trim( fgets( $to ) )
		) {
			if ( $this->processUser( $username ) && $this->delay ) {
				sleep( $this->delay );
			}
		}
		fclose( $to );

		$this->report();
		$this->output( "done.\n" );
	}

	/**
	 * @param string $username
	 * @return bool True if mail was sent (or attempted); false otherwise
	 */
	private function processUser( $username ) {
		$this->total++;
		$user = User::newFromName( $username );
		if ( !$user || !$user->getId() ) {
			$this->missing++;
			$this->output( "ERROR - Unknown user {$username}\n" );
			return false;
		}
		if ( !$user->canReceiveEmail() ) {
			$this->noreceive++;
			$this->output( "WARNING - User {$username} can't receive mail\n" );
			return false;
		}
		if ( in_array( $user->getName(), $this->optout, true ) ) {
			$this->optedout++;
			$this->output( "WARNING - User {$username} on opt-out list\n" );
			return false;
		}

		$this->output( "INFO - Emailing {$username} <{$user->getEmail()}>\n" );
		$status = $this->dryRun ?
			Status::newGood() :
			$user->sendMail(
				$this->subject, $this->body, $this->from, $this->replyto );
		if ( $status->isGood() ) {
			$this->ok++;
		} else {
			$this->failed++;
			$this->output( "ERROR - Send failed: {$status->getMessage()}\n" );
		}
		return true;
	}

	/**
	 * Hook handler for the UserMailerTransformMessage hook.
	 *
	 * @param MailAddress[] $to List of mail recipients
	 * @param MailAddress $from Mail sender
	 * @param string &$subject Message subject
	 * @param array &$headers Email headers
	 * @param string|array &$body Message body
	 * @param Message|string &$error Explanation of any error encountered
	 * @return bool True if mail should be sent; flase otherwise
	 */
	public function onUserMailerTransformMessage(
		$to, $from, &$subject, &$headers, &$body, &$error
	) {
		$headers['Precedence'] = 'bulk';
		if ( $this->optoutUrl ) {
			$headers['List-Unsubscribe'] = "<{$this->optoutUrl}>";
		}
		return true;
	}

	private function reportPcnt( $val ) {
		if ( $this->total > 0 ) {
			return $val / $this->total * 100.0;
		}
		return 0;
	}

	private function report() {
		$delta = microtime( true ) - $this->start;
		$format = '[%s]' .
			' processed: %d (%.1f/sec);' .
			' ok: %d (%.1f%%);' .
			' failed: %d (%.1f%%);' .
			' missing: %d (%.1f%%);' .
			' noreceive: %d (%.1f%%);' .
			' optedout: %d (%.1f%%);' .
			"\n";
		$this->output( sprintf( $format,
			wfTimestamp( TS_DB ),
			$this->total,     $this->total / $delta,
			$this->ok,        $this->reportPcnt( $this->ok ),
			$this->failed,    $this->reportPcnt( $this->failed ),
			$this->missing,   $this->reportPcnt( $this->missing ),
			$this->noreceive, $this->reportPcnt( $this->noreceive ),
			$this->optedout,  $this->reportPcnt( $this->optedout )
		) );
	}

	/**
	 * @return User|null Sender
	 */
	private function getSender() {
		if ( $this->hasOption( 'from' ) ) {
			$uname = $this->getOption( 'from' );
			$from = User::newFromName( $uname );
			if ( !$from || !$from->getId() ) {
				$this->fatalError( "ERROR - Unknown user {$uname}" );
			}
			return $from;
		}
		return null;
	}

	/**
	 * @return MailAddress|null reply-to
	 */
	private function getReplyTo() {
		if ( $this->hasOption( 'reply-to' ) ) {
			$uname = $this->getOption( 'reply-to' );
			$rt = User::newFromName( $uname );
			if ( !$rt || !$rt->getId() ) {
				$this->fatalError( "ERROR - Unknown user {$uname}" );
			}
			return MailAddress::newFromUser( $rt );
		}
		return null;
	}

	/**
	 * Get the filehandle pointed to by a parameter's value.
	 *
	 * @param string $param Parameter name
	 * @return resource Open file handle
	 */
	private function getFileHandle( $param ) {
		$fname = $this->getOption( $param );
		if ( !is_file( $fname ) ) {
			$this->fatalError( "ERROR - File not found: {$fname}" );
		}
		$fh = fopen( $fname, 'r' );
		if ( $fh === false ) {
			$this->fatalError( "ERROR - Could not open file: {$fname}" );
		}
		return $fh;
	}

	/**
	 * Get the contents of a file pointed to by a parameter's value.
	 *
	 * @param string $param Parameter name
	 * @return string File contents
	 */
	private function getFileContents( $param ) {
		$fname = $this->getOption( $param );
		if ( !is_file( $fname ) ) {
			$this->fatalError( "ERROR - File not found: {$fname}" );
		}
		$contents = file_get_contents( $fname );
		if ( $contents === false ) {
			$this->fatalError( "ERROR - Could not read file: {$fname}" );
		}
		return $contents;
	}

	/**
	 * Read an opt-out list from a wiki page.
	 *
	 * The page is expected to be a normal wiki page and to have list start
	 * and end markers in the wikitext source that surround the list. The list
	 * itself is expected to be one username per line in canonical form.
	 *
	 * Lots of assumptions for sure, but hey this is maintenance script. :)
	 *
	 * Also sets $this->optoutUrl as a side effect.
	 *
	 * @return string[] List of usernames
	 */
	private function getOptOutList() {
		$list = [];
		if ( $this->hasOption( 'optout' ) ) {
			$title = Title::newFromText( $this->getOption( 'optout' ) );
			if ( !$title->exists() ) {
				$this->fatalError( "ERROR - Opt-out page '{$title}' not found." );
			}
			$this->optoutUrl = $title->getFullURL(
				'', false, PROTO_CANONICAL );
			$rev = Revision::newFromTitle( $title );
			$content = ContentHandler::getContentText( $rev->getContent() );
			$inList = false;
			foreach ( explode( "\n", $content ) as $line ) {
				if ( !$inList ) {
					if ( $line == $this->optoutStart ) {
						$inList = true;
					}
				} else {
					if ( $line == $this->optoutEnd ) {
						break;
					}
					$list[] = trim( $line );
				}
			}
			if ( !$inList ) {
				$this->fatalError(
					"ERROR - List marker '{$this->optoutStart}' not found." );
			}
		}
		return $list;
	}

	public function getDbType() {
		return self::DB_NONE;
	}
}

$maintClass = 'SendBulkEmails';
require_once RUN_MAINTENANCE_IF_MAIN;
