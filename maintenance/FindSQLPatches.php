<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List new or updated SQL patches between two MediaWiki versions.
 *
 * Usage:
 *     php run.php MirahezeMagic:FindSQLPatches --from-version=current_version --to-version=new_version
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
 * @version 1.0
 */

use Miraheze\MirahezeMagic\Maintenance\Base\PhpAnalysisMaintenance;
use PhpToken;
use function array_merge;
use function basename;
use function count;
use function file_get_contents;
use function implode;
use function in_array;
use function is_file;
use function preg_match;
use function preg_replace;
use function str_contains;
use function strtolower;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DIR;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_FUNCTION;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_RETURN;
use const T_STRING;
use const T_VARIABLE;

class FindSQLPatches extends PhpAnalysisMaintenance {

	private const array UPDATE_CALLS = [
		'addextensionupdate',
		'addextensionupdateonvirtualdomain',
		'dropextensiontable',
		'addpostdatabaseupdatemaintenance',
	];

	private const string CORE_UPDATER = '/includes/Installer/MysqlUpdater.php';

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new or updated SQL patches between two MediaWiki versions.' );
	}

	public function execute() {
		$patches = $this->findNewOrUpdatedFiles(
			$this->findSqlPatches( $this->getFromVersion() ),
			$this->findSqlPatches( $this->getToVersion() )
		);
		foreach ( $patches as $patch ) {
			$this->output( $patch . "\n" );
		}

		$updates = $this->findNewInlineUpdates( $this->getFromVersion(), $this->getToVersion() );
		if ( $updates ) {
			$this->output( "\n" );
		}

		foreach ( $updates as $update ) {
			$this->output( $update['file'] . ':' . $update['line'] . ' ' . $update['text'] . "\n" );
		}

		$this->outputCount( count( $patches ) + count( $updates ) );
	}

	private function findSqlPatches( $version ) {
		$patches = [];

		$files = $this->findFilesRecursively( $this->getVersionPath( $version ) . '/**/*.sql' );

		foreach ( $files as $file ) {
			if (
				str_contains( $file, '/postgres/' ) ||
				str_contains( $file, '/sqlite/' ) ||
				str_contains( $file, '/tests/' )
			) {
				continue;
			}

			$patches[basename( $file )] = $file;
		}

		return $patches;
	}

	/**
	 * @return array<int, array{file: string, line: int, text: string}>
	 */
	private function findNewInlineUpdates( string $fromVersion, string $toVersion ): array {
		$from = $this->findInlineUpdates( $fromVersion );
		$new = [];
		foreach ( $this->findInlineUpdates( $toVersion ) as $key => $update ) {
			if ( !isset( $from[$key] ) ) {
				$new[] = $update;
			}
		}

		return $this->sortByLocation( $new );
	}

	/**
	 * @return array<string, array{file: string, line: int, text: string}>
	 */
	private function findInlineUpdates( string $version ): array {
		$base = $this->getVersionPath( $version );
		$updates = [];

		foreach ( [ 'extensions', 'skins' ] as $directory ) {
			foreach ( $this->iteratePhpFiles( $base . '/' . $directory ) as $file ) {
				$code = file_get_contents( $file );
				if ( $code !== false && preg_match( '/addExtensionUpdate|dropExtensionTable|addPostDatabaseUpdateMaintenance/', $code ) ) {
					$this->scanUpdateCalls( $this->tokenize( $code ), $this->relativePath( $file, $version ), $updates );
				}
			}
		}

		$core = $base . self::CORE_UPDATER;
		if ( is_file( $core ) ) {
			$this->scanCoreUpdateList( $this->tokenize( (string)file_get_contents( $core ) ), $this->relativePath( $core, $version ), $updates );
		}

		return $updates;
	}

	private function hasPatchPath( array $tokens ): bool {
		foreach ( $tokens as $token ) {
			if ( $token->is( T_DIR ) ) {
				return true;
			}

			if (
				$token->is( [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ] ) &&
				preg_match( '/\.(sql|json)/i', $token->text )
			) {
				return true;
			}

			if ( $token->is( T_VARIABLE ) && preg_match( '/path|dir|base|sql|patch/i', $token->text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int, PhpToken[]> $arguments
	 */
	private function describeArguments( array $arguments ): string {
		$parts = [];
		foreach ( $arguments as $argument ) {
			$text = '';
			foreach ( $argument as $token ) {
				$text .= $token->text;
			}

			$parts[] = preg_replace( '/^"([^"$\\\\]*)"$/', "'$1'", $text );
		}

		return implode( ', ', $parts );
	}

	private function addUpdate( array &$updates, string $file, int $line, string $call, array $arguments ): void {
		$text = $call . '( ' . $this->describeArguments( $arguments ) . ' )';
		$updates[$file . '|' . $text] ??= [ 'file' => $file, 'line' => $line, 'text' => $text ];
	}

	private function scanUpdateCalls( array $tokens, string $file, array &$updates ): void {
		$count = count( $tokens );
		for ( $i = 1; $i < $count - 1; $i++ ) {
			$token = $tokens[$i];
			if (
				!$token->is( T_STRING ) ||
				!$tokens[$i - 1]->is( [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ] ) ||
				$tokens[$i + 1]->text !== '(' ||
				!in_array( strtolower( $token->text ), self::UPDATE_CALLS, true )
			) {
				continue;
			}

			$name = strtolower( $token->text );
			$arguments = $this->splitArguments( $tokens, $i + 1 );
			if ( !$arguments ) {
				continue;
			}

			if ( $name === 'addextensionupdate' || $name === 'addextensionupdateonvirtualdomain' ) {
				$first = $arguments[0];
				if ( count( $first ) < 2 || $first[0]->text !== '[' ) {
					continue;
				}

				$arguments = $this->splitArguments( $first, 0 );
			} elseif ( $name === 'dropextensiontable' && count( $arguments ) > 1 ) {
				continue;
			}

			$flat = [];
			foreach ( $arguments as $argument ) {
				$flat = array_merge( $flat, $argument );
			}

			if ( !$this->hasPatchPath( $flat ) ) {
				$this->addUpdate( $updates, $file, $token->line, $token->text, $arguments );
			}
		}
	}

	private function scanCoreUpdateList( array $tokens, string $file, array &$updates ): void {
		$count = count( $tokens );
		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( !$tokens[$i]->is( T_FUNCTION ) || $tokens[$i + 1]->text !== 'getCoreUpdateList' ) {
				continue;
			}

			for ( $j = $i; $j < $count && !$tokens[$j]->is( T_RETURN ); $j++ ) {
				continue;
			}

			$depth = 0;
			for ( $k = $j; $k < $count; $k++ ) {
				$text = $tokens[$k]->text;
				if ( $text === '[' ) {
					$depth++;
					if ( $depth === 2 && isset( $tokens[$k + 1] ) && $tokens[$k + 1]->is( T_CONSTANT_ENCAPSED_STRING ) ) {
						$arguments = $this->splitArguments( $tokens, $k );
						$flat = [];
						foreach ( $arguments as $argument ) {
							$flat = array_merge( $flat, $argument );
						}

						if ( !$this->hasPatchPath( $flat ) ) {
							$this->addUpdate( $updates, $file, $tokens[$k]->line, 'core', $arguments );
						}
					}
				} elseif ( $text === ']' ) {
					$depth--;
					if ( $depth === 0 ) {
						return;
					}
				}
			}
		}
	}
}

// @codeCoverageIgnoreStart
return FindSQLPatches::class;
// @codeCoverageIgnoreEnd
