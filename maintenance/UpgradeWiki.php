<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * See https://meta.miraheze.org/wiki/Tech:Upgrading_MediaWiki for documentation.
 *
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
 * @ingroup MirahezeMagic
 * @author Universal Omega
 * @version 2.0
 */

use MediaWiki\Exception\MWExceptionHandler;
use MediaWiki\Http\Telemetry;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Maintenance\LoggedUpdateMaintenance;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionRegistry;
use MwSql;
use Throwable;
use function basename;
use function class_exists;
use function date;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;
use function register_shutdown_function;
use function str_starts_with;
use const FILE_APPEND;
use const MW_VERSION;

class UpgradeWiki extends LoggedUpdateMaintenance {

	private ?string $currentStep = null;
	private bool $completed = false;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Run a wiki upgrade defined in a JSON file (patches + maintenance steps).' );
		$this->addOption( 'json', 'Path to JSON file.', true, true );
		$this->addOption(
			'change-version',
			'Run ChangeMediaWikiVersion first, setting mwversion to the JSON\'s mwversion key, before any patches or maintenance scripts run.'
		);
		$this->requireExtension( 'MirahezeMagic' );
	}

	protected function getUpdateKey(): string {
		$jsonPath = $this->getOption( 'json' );
		$json = $this->loadJson( $jsonPath );
		return "upgrade-wiki-{$json['mwversion']}";
	}

	public function updateSkippedMessage(): string {
		$wiki = $this->getOption( 'wiki' );
		$jsonPath = $this->getOption( 'json' );
		$json = $this->loadJson( $jsonPath );
		return "$wiki has already been upgraded to {$json['mwversion']}.";
	}

	protected function doDBUpdates(): bool {
		$wiki = $this->getOption( 'wiki' );
		$jsonPath = $this->getOption( 'json' );

		$this->currentStep = "loading JSON file '$jsonPath'";
		$this->registerFailureShutdownHandler( $wiki );

		$json = $this->loadJson( $jsonPath );

		$this->output( "=== Running based on JSON '$jsonPath' for wiki '$wiki' ===\n" );

		try {
			$this->assertRunningVersion( $json );
			if ( $this->hasOption( 'change-version' ) ) {
				$this->runVersionChange( $wiki, $json );
			}

			$this->runPatchesSection( $wiki, $json, 'pre_patches', "=== Running pre-maintenance SQL patches ===\n" );
			$this->runMaintenanceSection( $wiki, $json );
			$this->runPatchesSection( $wiki, $json, 'post_patches', "=== Running post-maintenance SQL patches ===\n" );
			$this->output( "All steps completed.\n" );
			$this->completed = true;
			return true;
		} catch ( Throwable $t ) {
			MWExceptionHandler::rollbackPrimaryChangesAndLog( $t );
			$this->logToFile( $t, $wiki );
			$logger = LoggerFactory::getInstance( 'UpgradeWiki' );
			$logger->critical( 'UpgradeWiki failed on {wiki} during {step}: {message}', [
				'exception' => $t,
				'message' => $t->getMessage(),
				'step' => $this->currentStep,
				'wiki' => $wiki,
			] );

			$this->error( "Upgrade failed during {$this->currentStep}: {$t->getMessage()}" );
			$this->completed = true;
			return false;
		}
	}

	private function registerFailureShutdownHandler( string $wiki ): void {
		register_shutdown_function( function () use ( $wiki ): void {
			if ( $this->completed ) {
				return;
			}

			$step = $this->currentStep ?? 'an unknown step';
			$logFile = '/var/log/mediawiki/debuglogs/UpgradeWiki-exceptions.log';
			$requestId = Telemetry::getInstance()->getRequestId();
			$time = date( 'Y-m-d H:i:s' );
			$message = "$wiki [$requestId $time] UpgradeWiki exited unexpectedly while $step. "
				. "This is almost always a fatalError() call, check this run's stderr output "
				. "for the actual message.\n\n";
			file_put_contents( $logFile, $message, FILE_APPEND );

			$logger = LoggerFactory::getInstance( 'UpgradeWiki' );
			$logger->critical( 'UpgradeWiki on {wiki} exited unexpectedly while {step}', [
				'step' => $step,
				'wiki' => $wiki,
			] );
		} );
	}

	private function logToFile( Throwable $t, string $wiki ): void {
		$logFile = '/var/log/mediawiki/debuglogs/UpgradeWiki-exceptions.log';
		$requestId = Telemetry::getInstance()->getRequestId();
		$time = date( 'Y-m-d H:i:s' );
		$message = "$wiki [$requestId $time] UpgradeWiki exception\n$t\n\n";
		file_put_contents( $logFile, $message, FILE_APPEND );
	}

	private function assertRunningVersion( array $json ): void {
		$mwversion = $json['mwversion'] ?? null;
		if ( !is_string( $mwversion ) || $mwversion === '' ) {
			$this->currentStep = "validating JSON key 'mwversion'";
			$this->fatalError( "JSON key 'mwversion' must be a non-empty string." );
		}

		if ( !str_starts_with( MW_VERSION, $mwversion ) ) {
			$this->currentStep = 'validating running MediaWiki version';
			$this->fatalError(
				'This script is running under MediaWiki ' . MW_VERSION . ", but the JSON targets $mwversion. "
				. 'Make sure to run this script on the target version.'
			);
		}
	}

	private function runVersionChange( string $wiki, array $json ): void {
		$mwversion = $json['mwversion'];

		$this->output( "=== Running ChangeMediaWikiVersion to set mwversion to '$mwversion' ===\n" );
		$this->currentStep = "running ChangeMediaWikiVersion to set mwversion to '$mwversion'";
		$this->runMaintenanceClass( $wiki, ChangeMediaWikiVersion::class, [ 'mwversion' => $mwversion ], [] );
	}

	private function runPatchesSection( string $wiki, array $json, string $key, string $header ): void {
		$items = $json[$key] ?? [];
		if ( $items === [] ) {
			return;
		}

		if ( !is_array( $items ) ) {
			$this->currentStep = "validating JSON key '$key'";
			$this->fatalError( "JSON key '$key' must be an array." );
		}

		$this->output( $header );
		foreach ( $items as $item ) {
			$requiredExtension = $item['if_extension_enabled'] ?? null;
			if ( $requiredExtension !== null && !$this->hasExtension( $requiredExtension ) ) {
				$this->output( "==> Skipping SQL: required extension '$requiredExtension' not enabled\n" );
				continue;
			}

			$filename = $this->normalizePatchItemToFilename( $item, $key );
			$this->currentStep = "running SQL patch '$filename' from '$key'";
			$this->runSqlFile( $wiki, $filename );
		}
	}

	private function runMaintenanceSection( string $wiki, array $json ): void {
		$items = $json['maintenance'] ?? [];
		if ( $items === [] ) {
			return;
		}

		if ( !is_array( $items ) ) {
			$this->currentStep = "validating JSON key 'maintenance'";
			$this->fatalError( "JSON key 'maintenance' must be an array." );
		}

		$this->output( "=== Running maintenance scripts ===\n" );
		foreach ( $items as $idx => $item ) {
			if ( !is_array( $item ) ) {
				$this->currentStep = "validating maintenance[$idx]";
				$this->fatalError( "maintenance[$idx] must be an object." );
			}

			$requiredExtension = $item['if_extension_enabled'] ?? null;
			if ( $requiredExtension !== null && !$this->hasExtension( $requiredExtension ) ) {
				$this->output( "==> Skipping maintenance: required extension '$requiredExtension' not enabled\n" );
				continue;
			}

			$class = $item['class'] ?? null;
			if ( !is_string( $class ) || $class === '' ) {
				$this->currentStep = "validating maintenance[$idx].class";
				$this->fatalError( "maintenance[$idx].class must be a non-empty string." );
			}

			$options = $item['options'] ?? [];
			$args = $item['args'] ?? [];
			if ( $options !== [] && !is_array( $options ) ) {
				$this->currentStep = "validating maintenance[$idx].options for $class";
				$this->fatalError( "maintenance[$idx].options must be an object (key/value) if present." );
			}

			if ( $args !== [] && !is_array( $args ) ) {
				$this->currentStep = "validating maintenance[$idx].args for $class";
				$this->fatalError( "maintenance[$idx].args must be an array if present." );
			}

			$this->output( "==> Maintenance: $class\n" );
			$this->currentStep = "running maintenance class '$class'";
			$this->runMaintenanceClass( $wiki, $class, $options, $args );
		}
	}

	private function runSqlFile( string $wiki, string $filename ): void {
		$this->output( "==> SQL: " . basename( $filename ) . "\n" );
		$maint = new MwSql();
		$maint->setOption( 'wikidb', $wiki );
		$maint->setArg( 0, $filename );
		$maint->execute();
	}

	private function runMaintenanceClass( string $wiki, string $class, array $options, array $args ): void {
		if ( !class_exists( $class ) ) {
			$this->fatalError( "Maintenance class not found: $class" );
		}

		/** @var Maintenance $maint */
		$maint = new $class();
		'@phan-var Maintenance $maint';
		if ( !$this->hasOptionKey( $options, 'wiki' ) ) {
			$maint->setOption( 'wiki', $wiki );
		}

		foreach ( $options as $key => $value ) {
			if ( !is_string( $key ) || $key === '' ) {
				$this->fatalError( "Invalid option key for $class (must be non-empty string)." );
			}

			if (
				$value === null ||
				is_string( $value ) ||
				is_int( $value ) ||
				is_bool( $value )
			) {
				$maint->setOption( $key, $value );
				continue;
			}

			$this->fatalError(
				"Option '$key' for $class must be string/int/bool/null (got non-scalar)."
			);
		}

		$argIndex = 0;
		foreach ( $args as $arg ) {
			if ( !is_string( $arg ) && !is_int( $arg ) ) {
				$this->fatalError( "Args for $class must be strings/ints." );
			}

			$maint->setArg( $argIndex, (string)$arg );
			$argIndex++;
		}

		$maint->execute();
	}

	private function hasExtension( string $name ): bool {
		return ExtensionRegistry::getInstance()->isLoaded( $name );
	}

	private function hasOptionKey( array $options, string $key ): bool {
		return isset( $options[$key] );
	}

	private function normalizePatchItemToFilename( mixed $item, string $sectionKey ): string {
		if ( is_string( $item ) && $item !== '' ) {
			return $item;
		}

		if ( is_array( $item ) ) {
			$file = $item['file'] ?? null;
			if ( is_string( $file ) && $file !== '' ) {
				return $file;
			}
		}

		$this->fatalError(
			"Each entry in '$sectionKey' must be either a string filename or {\"file\": \"...\"}."
		);
	}

	private function loadJson( string $filename ): array {
		$json = file_get_contents( $filename );
		if ( $json === false ) {
			$this->fatalError( "Failed to read JSON file: $filename" );
		}

		$data = json_decode( $json, true );
		if ( !is_array( $data ) ) {
			$this->fatalError( "JSON file did not decode to an object: $filename" );
		}

		return $data;
	}
}

// @codeCoverageIgnoreStart
return UpgradeWiki::class;
// @codeCoverageIgnoreEnd
