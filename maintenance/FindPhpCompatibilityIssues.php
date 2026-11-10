<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/** Temporary script. Will be removed once everything is compatible with PHP 8.5 and 8.6. */

use Composer\Semver\Semver;
use Miraheze\MirahezeMagic\Maintenance\Base\PhpAnalysisMaintenance;
use PhpToken;
use Throwable;
use function array_pop;
use function class_exists;
use function count;
use function end;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function ltrim;
use function ord;
use function preg_match;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function trim;
use const T_BOOL_CAST;
use const T_CASE;
use const T_CLASS;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DEFAULT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_ARROW;
use const T_DOUBLE_CAST;
use const T_DOUBLE_COLON;
use const T_ENUM;
use const T_FINALLY;
use const T_FUNCTION;
use const T_INT_CAST;
use const T_INTERFACE;
use const T_NAME_FULLY_QUALIFIED;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_READONLY;
use const T_RETURN;
use const T_STRING;
use const T_STRING_CAST;
use const T_SWITCH;
use const T_TRAIT;
use const T_VARIABLE;
use const T_YIELD;
use const T_YIELD_FROM;

class FindPhpCompatibilityIssues extends PhpAnalysisMaintenance {

	protected const bool COMPARES_VERSIONS = false;

	private int $position = 0;

	private const array TARGETS = [ '8.5', '8.6' ];

	private const array CORE_DIRECTORIES = [ 'includes', 'languages', 'maintenance' ];

	private const array FUNCTIONS = [
		'curl_close' => [ '8.5', 'DEPRECATED', 'no-op since PHP 8.0, remove the call' ],
		'curl_share_close' => [ '8.5', 'DEPRECATED', 'no-op since PHP 8.0, remove the call' ],
		'finfo_close' => [ '8.5', 'DEPRECATED', 'no-op, remove the call' ],
		'imagedestroy' => [ '8.5', 'DEPRECATED', 'no-op, remove the call' ],
		'xml_parser_free' => [ '8.5', 'DEPRECATED', 'no-op since PHP 8.0, remove the call' ],
		'mysqli_execute' => [ '8.5', 'DEPRECATED', 'use mysqli_stmt_execute()' ],
		'socket_set_timeout' => [ '8.5', 'DEPRECATED', 'use stream_set_timeout()' ],
		'pcntl_setns' => [ '8.5', 'REMOVED', '' ],
		'is_double' => [ '8.6', 'DEPRECATED', 'use is_float()' ],
		'is_integer' => [ '8.6', 'DEPRECATED', 'use is_int()' ],
		'is_long' => [ '8.6', 'DEPRECATED', 'use is_int()' ],
		'doubleval' => [ '8.6', 'DEPRECATED', 'use floatval()' ],
		'spl_object_hash' => [ '8.6', 'DEPRECATED', 'use spl_object_id()' ],
		'spl_classes' => [ '8.6', 'DEPRECATED', '' ],
		'strcoll' => [ '8.6', 'DEPRECATED', 'use Collator' ],
		'metaphone' => [ '8.6', 'DEPRECATED', '' ],
		'mysqli_get_charset' => [ '8.6', 'DEPRECATED', '' ],
		'mysqli_stmt_init' => [ '8.6', 'DEPRECATED', 'use mysqli::prepare()' ],
		'mb_ereg' => [ '8.6', 'DEPRECATED', 'mbregex, use preg_match() with the u modifier' ],
		'mb_eregi' => [ '8.6', 'DEPRECATED', 'mbregex, use preg_match() with the u modifier' ],
		'mb_ereg_replace' => [ '8.6', 'DEPRECATED', 'mbregex, use preg_replace() with the u modifier' ],
		'mb_eregi_replace' => [ '8.6', 'DEPRECATED', 'mbregex, use preg_replace() with the u modifier' ],
		'mb_ereg_replace_callback' => [ '8.6', 'DEPRECATED', 'mbregex, use preg_replace_callback() with the u modifier' ],
		'mb_split' => [ '8.6', 'DEPRECATED', 'mbregex, use preg_split() with the u modifier' ],
		'mb_ereg_match' => [ '8.6', 'DEPRECATED', 'mbregex, use preg_match() with the u modifier' ],
		'mb_ereg_search' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_ereg_search_pos' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_ereg_search_regs' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_ereg_search_init' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_ereg_search_getregs' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_ereg_search_getpos' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_ereg_search_setpos' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_regex_encoding' => [ '8.6', 'DEPRECATED', 'mbregex' ],
		'mb_regex_set_options' => [ '8.6', 'DEPRECATED', 'mbregex' ],
	];

	private const array CONSTANTS = [
		'DATE_RFC7231' => [ '8.5', 'DEPRECATED', 'ignores the timezone and always uses GMT' ],
		'SORT_LOCALE_STRING' => [ '8.6', 'DEPRECATED', 'use Collator' ],
	];

	private const array METHODS = [
		'stmt_init' => [ '8.6', 'DEPRECATED', 'mysqli::stmt_init(), use mysqli::prepare()' ],
		'sqlitecreatefunction' => [ '8.5', 'DEPRECATED', 'use Pdo\\Sqlite::createFunction()' ],
		'sqlitecreateaggregate' => [ '8.5', 'DEPRECATED', 'use Pdo\\Sqlite::createAggregate()' ],
		'sqlitecreatecollation' => [ '8.5', 'DEPRECATED', 'use Pdo\\Sqlite::createCollation()' ],
		'pgsqlcopyfromarray' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::copyFromArray()' ],
		'pgsqlcopyfromfile' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::copyFromFile()' ],
		'pgsqlcopytoarray' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::copyToArray()' ],
		'pgsqlcopytofile' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::copyToFile()' ],
		'pgsqllobcreate' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::lobCreate()' ],
		'pgsqllobopen' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::lobOpen()' ],
		'pgsqllobunlink' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::lobUnlink()' ],
		'pgsqlgetnotify' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::getNotify()' ],
		'pgsqlgetpid' => [ '8.5', 'DEPRECATED', 'use Pdo\\Pgsql::getPid()' ],
	];

	private const array CASTS = [
		'integer' => '(int)',
		'boolean' => '(bool)',
		'double' => '(float)',
		'binary' => '(string)',
	];

	private const array RESERVED_NAMES = [ 'let', 'is', '_' ];

	private const array CALL_EXCLUDED_PREVIOUS = [
		T_OBJECT_OPERATOR,
		T_NULLSAFE_OBJECT_OPERATOR,
		T_DOUBLE_COLON,
		T_FUNCTION,
		T_NEW,
		T_CONST,
		T_NS_SEPARATOR,
	];

	private const array NAME_TOKEN_IDS = [ T_STRING, T_NAME_FULLY_QUALIFIED ];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List code in core, extensions and skins that is deprecated, removed or changed in PHP 8.5 and 8.6.' );
		$this->addOption( 'php', 'Only check compatibility with this PHP version (8.5 or 8.6)', false, true );
		$this->addEnabledOnlyOption();
		$this->addExtensionOption();
	}

	public function execute(): void {
		$php = $this->getOption( 'php' );
		if ( $php !== null && !in_array( $php, self::TARGETS, true ) ) {
			$this->fatalError( 'Unknown PHP version ' . $php . ', use ' . implode( ' or ', self::TARGETS ) );
		}

		$targets = $php === null ? self::TARGETS : [ $php ];
		$version = $this->getVersion();
		$base = $this->getVersionPath( $version );
		$entries = $this->selectEntries( $this->findManifestFiles( $version ) );

		$roots = [];
		if ( !$this->hasOption( 'extension' ) ) {
			foreach ( self::CORE_DIRECTORIES as $directory ) {
				if ( is_dir( $base . '/' . $directory ) ) {
					$roots[] = $base . '/' . $directory;
				}
			}
		}

		foreach ( $entries as $entry ) {
			$roots[] = $entry['path'];
		}

		$found = [];
		$fileCount = 0;
		foreach ( $roots as $root ) {
			foreach ( $this->iteratePhpFiles( $root ) as $file ) {
				$code = file_get_contents( $file );
				if ( $code === false ) {
					continue;
				}

				$fileCount++;
				$this->scanFile( $code, $this->relativePath( $file, $version ), $targets, $found );
			}
		}

		if ( !$this->hasOption( 'extension' ) ) {
			$this->checkComposer( $base . '/composer.json', 'core', $version, $targets, $found );
		}

		foreach ( $entries as $entry ) {
			$this->checkComposer( $entry['path'] . '/composer.json', $entry['name'], $version, $targets, $found );
			$platform = $entry['info']['requires']['platform']['php'] ?? null;
			if ( is_string( $platform ) ) {
				$this->checkConstraint( $platform, $entry['name'], $this->relativePath( $entry['file'], $version ), $targets, $found );
			}
		}

		$this->report( $found, $fileCount );
	}

	private function report( array $found, int $fileCount ): void {
		$totals = [];
		foreach ( $this->sortByLocation( $found ) as $item ) {
			$note = $item['note'] !== '' ? ' (' . $item['note'] . ')' : '';
			$this->output( $item['file'] . ':' . $item['line'] . ' PHP ' . $item['php'] . ' ' . $item['status'] . ' ' . $item['name'] . $note . "\n" );
			$totals[$item['php']] = ( $totals[$item['php']] ?? 0 ) + 1;
		}

		$this->output( "\nScanned " . $fileCount . " files\n" );
		foreach ( $totals as $php => $total ) {
			$this->output( 'PHP ' . $php . ': ' . $total . "\n" );
		}

		$this->outputCount( count( $found ) );
	}

	private function add( array &$found, array $targets, string $file, int $line, array $rule, string $name ): void {
		if ( !in_array( $rule[0], $targets, true ) ) {
			return;
		}

		$found[] = [
			'file' => $file,
			'line' => $line,
			'php' => $rule[0],
			'status' => $rule[1],
			'name' => $name,
			'note' => $rule[2],
			'pos' => $this->position,
		];
	}

	private function checkComposer( string $path, string $name, string $version, array $targets, array &$found ): void {
		if ( !is_file( $path ) ) {
			return;
		}

		$info = json_decode( file_get_contents( $path ), true );
		$constraint = is_array( $info ) ? ( $info['require']['php'] ?? null ) : null;
		if ( is_string( $constraint ) ) {
			$this->checkConstraint( $constraint, $name, $this->relativePath( $path, $version ), $targets, $found );
		}
	}

	private function checkConstraint( string $constraint, string $name, string $file, array $targets, array &$found ): void {
		if ( !class_exists( Semver::class ) ) {
			return;
		}

		foreach ( $targets as $target ) {
			try {
				$allowed = Semver::satisfies( $target . '.0', $constraint );
			} catch ( Throwable ) {
				continue;
			}

			if ( !$allowed ) {
				$this->add( $found, $targets, $file, 1, [ $target, 'INCOMPATIBLE', $name . ' requires php ' . $constraint ], 'php constraint' );
			}
		}
	}

	private function scanFile( string $code, string $file, array $targets, array &$found ): void {
		$tokens = $this->tokenize( $code );
		$count = count( $tokens );
		$before = count( $found );
		$stack = [];
		$pending = null;
		$backtick = false;
		$serialization = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[$i];
			$id = $token->id;
			$text = $token->text;
			$line = $token->line;
			$this->position = $token->pos;
			$previous = $tokens[$i - 1] ?? null;
			$next = $tokens[$i + 1] ?? null;

			if ( $text === '{' ) {
				$stack[] = $pending ?? 'other';
				$pending = null;
				continue;
			}

			if ( $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES ) {
				$stack[] = 'other';
				continue;
			}

			if ( $text === '}' ) {
				array_pop( $stack );
				continue;
			}

			if ( $id === T_SWITCH ) {
				$pending = 'switch';
			} elseif ( $id === T_ENUM ) {
				$pending = 'enum';
			}

			if ( $id === ord( '`' ) ) {
				$backtick = !$backtick;
				if ( $backtick ) {
					$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'use shell_exec()' ], 'backtick operator' );
				}

				continue;
			}

			$cast = self::CASTS[strtolower( str_replace( [ ' ', "\t", "\n", '(', ')' ], '', $text ) )] ?? null;
			if ( $cast !== null && in_array( $id, [ T_INT_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_STRING_CAST ], true ) ) {
				$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'use ' . $cast ], 'cast ' . $text );
				continue;
			}

			if ( $id === T_VARIABLE && $text === '$http_response_header' ) {
				$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'use http_get_last_response_headers()' ], $text );
				continue;
			}

			if ( ( $id === T_CASE || $id === T_DEFAULT ) && end( $stack ) === 'switch' ) {
				if ( $this->terminatedBySemicolon( $tokens, $i ) ) {
					$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'end it with a colon' ], 'semicolon after ' . $text );
				}

				continue;
			}

			if ( $id === T_FINALLY && $next !== null && $next->text === '{' ) {
				foreach ( $this->bodyIndexes( $tokens, $i + 1 ) as $index ) {
					if ( $tokens[$index]->id === T_RETURN ) {
						$this->position = $tokens[$index]->pos;
						$this->add( $found, $targets, $file, $tokens[$index]->line, [ '8.6', 'DEPRECATED', '' ], 'return inside finally' );
					}
				}

				continue;
			}

			if ( in_array( $id, [ T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ], true ) ) {
				if ( $next !== null && $next->id === T_STRING ) {
					$this->reportSerialization( $serialization, $file, $targets, $found );
					$serialization = [];
				}

				if ( $next !== null && $next->id === T_STRING && in_array( strtolower( $next->text ), self::RESERVED_NAMES, true ) ) {
					$this->add( $found, $targets, $file, $line, [ '8.6', 'DEPRECATED', 'reserved name' ], $text . ' ' . $next->text );
				}

				continue;
			}

			if ( $id === T_CONST && $next !== null ) {
				$name = strtolower( $next->text );
				if ( in_array( $name, self::RESERVED_NAMES, true ) || $name === 'namespace' ) {
					$this->add( $found, $targets, $file, $line, [ '8.6', 'DEPRECATED', 'reserved name' ], 'const ' . $next->text );
				}

				continue;
			}

			if ( $id === T_FUNCTION ) {
				$j = $next !== null && $next->text === '&' ? $i + 2 : $i + 1;
				$nameToken = $tokens[$j] ?? null;
				if ( $nameToken === null || ( $nameToken->id !== T_STRING && $nameToken->id !== T_READONLY ) ) {
					continue;
				}

				$name = strtolower( $nameToken->text );
				if ( $name === 'readonly' ) {
					$this->add( $found, $targets, $file, $line, [ '8.6', 'DEPRECATED', 'reserved name' ], 'function readonly' );
				} elseif ( in_array( $name, [ '__construct', '__destruct' ], true ) ) {
					foreach ( $this->bodyIndexes( $tokens, $j ) as $index ) {
						$bodyToken = $tokens[$index];
						$bodyNext = $tokens[$index + 1] ?? null;
						$returnsValue = $bodyToken->id === T_RETURN && $bodyNext !== null && $bodyNext->text !== ';';
						if ( $returnsValue || $bodyToken->id === T_YIELD || $bodyToken->id === T_YIELD_FROM ) {
							$this->position = $bodyToken->pos;
							$this->add( $found, $targets, $file, $bodyToken->line, [ '8.6', 'DEPRECATED', '' ], 'value returned from ' . $nameToken->text . '()' );
						}
					}
				} elseif ( $name === '__debuginfo' ) {
					foreach ( $this->bodyIndexes( $tokens, $j ) as $index ) {
						$bodyNext = $tokens[$index + 1] ?? null;
						if ( $tokens[$index]->id === T_RETURN && $bodyNext !== null && strtolower( $bodyNext->text ) === 'null' ) {
							$this->position = $tokens[$index]->pos;
							$this->add( $found, $targets, $file, $tokens[$index]->line, [ '8.5', 'DEPRECATED', 'return an empty array' ], '__debugInfo() returning null' );
						}
					}
				} elseif ( in_array( $name, [ '__sleep', '__wakeup', '__serialize', '__unserialize' ], true ) ) {
					$serialization[$name] ??= $line;
				}

				continue;
			}

			if ( $id === T_CONSTANT_ENCAPSED_STRING ) {
				$this->scanString( $tokens, $i, $file, $targets, $found );
				continue;
			}

			if ( $id === T_STRING && strtolower( $text ) === 'null' && $next !== null ) {
				$beforePrevious = $tokens[$i - 2] ?? null;
				$isKey = $next->id === T_DOUBLE_ARROW && $previous !== null && in_array( $previous->text, [ '[', ',', '(' ], true );
				$isIndex = $next->text === ']' && $previous !== null && $previous->text === '[' &&
					$beforePrevious !== null && in_array( $beforePrevious->id, [ T_VARIABLE, T_STRING ], true );
				if ( $isKey || $isIndex ) {
					$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'use an empty string' ], 'null as array key' );
				}

				continue;
			}

			if ( !in_array( $id, self::NAME_TOKEN_IDS, true ) || $next === null ) {
				continue;
			}

			$name = ltrim( $text, '\\' );
			$lower = strtolower( $name );
			$excluded = $previous !== null && in_array( $previous->id, self::CALL_EXCLUDED_PREVIOUS, true );

			if ( $next->id === T_DOUBLE_COLON && $name === 'PDO' ) {
				$constant = $tokens[$i + 2] ?? null;
				if ( $constant !== null && preg_match( '/^(MYSQL|SQLITE|PGSQL|ODBC|OCI|FIREBIRD|DBLIB|SQLSRV)_/', $constant->text ) ) {
					$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'use the Pdo\\ driver class constant' ], 'PDO::' . $constant->text );
				}

				continue;
			}

			if ( $next->text === '(' ) {
				if ( $previous !== null && in_array( $previous->id, [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ], true ) ) {
					if ( isset( self::METHODS[$lower] ) ) {
						$this->add( $found, $targets, $file, $line, self::METHODS[$lower], '->' . $name . '() (possible)' );
					} elseif ( in_array( $lower, [ 'fgetcsv', 'fputcsv', 'setcsvcontrol', 'getcsvcontrol' ], true ) ) {
						$this->add( $found, $targets, $file, $line, [ '8.6', 'DEPRECATED', 'SplFileObject CSV methods' ], '->' . $name . '() (possible)' );
					}

					continue;
				}

				if ( $excluded ) {
					continue;
				}

				if ( isset( self::FUNCTIONS[$lower] ) ) {
					$this->add( $found, $targets, $file, $line, self::FUNCTIONS[$lower], $name . '()' );
				}

				$this->scanCall( $tokens, $i, $lower, $file, $targets, $found );
				continue;
			}

			if ( $excluded || $next->id === T_DOUBLE_COLON || $next->id === T_NS_SEPARATOR ) {
				continue;
			}

			$upper = strtoupper( $name );
			if ( isset( self::CONSTANTS[$upper] ) ) {
				$this->add( $found, $targets, $file, $line, self::CONSTANTS[$upper], $name );
			} elseif ( str_starts_with( $upper, 'MHASH_' ) ) {
				$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'mhash constants' ], $name );
			}
		}

		$this->reportSerialization( $serialization, $file, $targets, $found );
		$this->tagGuarded( $found, $before, $this->findGuards( $tokens, [ 'php_version' ], [ 'php_version_id' ] ) );
	}

	private function tagGuarded( array &$found, int $before, array $guards ): void {
		if ( !$guards ) {
			return;
		}

		for ( $k = $before, $total = count( $found ); $k < $total; $k++ ) {
			$check = $this->findGuardCheck( $guards, $found[$k]['pos'], $this->versionId( $found[$k]['php'] ) );
			if ( $check !== null ) {
				$found[$k]['note'] = ( $found[$k]['note'] !== '' ? $found[$k]['note'] . ', ' : '' ) . 'guarded by ' . $check;
			}
		}
	}

	private function reportSerialization( array $serialization, string $file, array $targets, array &$found ): void {
		if ( isset( $serialization['__serialize'], $serialization['__unserialize'] ) ) {
			return;
		}

		foreach ( [ '__sleep', '__wakeup' ] as $method ) {
			if ( isset( $serialization[$method] ) ) {
				$this->add( $found, $targets, $file, $serialization[$method], [ '8.5', 'SOFT', 'implement __serialize() and __unserialize()' ], $method . '()' );
			}
		}
	}

	private function scanCall( array $tokens, int $i, string $name, string $file, array $targets, array &$found ): void {
		$line = $tokens[$i]->line;
		if ( !in_array( $name, [ 'define', 'is_a', 'is_subclass_of', 'array_key_exists', 'ini_set', 'ini_get', 'ini_restore' ], true ) ) {
			return;
		}

		$arguments = $this->splitArguments( $tokens, $i + 1 );
		$single = static fn ( int $index ): ?PhpToken => count( $arguments[$index] ?? [] ) === 1 ? $arguments[$index][0] : null;

		if ( $name === 'define' && count( $arguments ) >= 3 ) {
			$this->add( $found, $targets, $file, $line, [ '8.6', 'DEPRECATED', 'the third argument is ignored' ], 'define() with $case_insensitive' );
		} elseif ( in_array( $name, [ 'is_a', 'is_subclass_of' ], true ) ) {
			$allow = $single( 2 );
			if ( $allow !== null && strtolower( $allow->text ) === 'false' ) {
				$this->add( $found, $targets, $file, $line, [ '8.6', 'DEPRECATED', 'when the first argument is a string' ], $name . '() with $allow_string false (possible)' );
			}
		} elseif ( $name === 'array_key_exists' ) {
			$key = $single( 0 );
			if ( $key !== null && strtolower( $key->text ) === 'null' ) {
				$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'null key' ], 'array_key_exists() with null' );
			}
		} else {
			$setting = $single( 0 );
			if ( $setting === null || $setting->id !== T_CONSTANT_ENCAPSED_STRING ) {
				return;
			}

			$setting = strtolower( trim( $setting->text, '\'"' ) );
			if ( $setting === 'report_memleaks' ) {
				$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', '' ], 'ini setting report_memleaks' );
			} elseif ( $setting === 'disable_classes' ) {
				$this->add( $found, $targets, $file, $line, [ '8.5', 'REMOVED', '' ], 'ini setting disable_classes' );
			} elseif ( $setting === 'intl.error_level' && $name === 'ini_set' ) {
				$value = $single( 1 );
				if ( $value === null || trim( $value->text, '\'"' ) !== '0' ) {
					$this->add( $found, $targets, $file, $line, [ '8.5', 'DEPRECATED', 'use intl.use_exceptions' ], 'ini setting intl.error_level' );
				}
			}
		}
	}

	private function scanString( array $tokens, int $i, string $file, array $targets, array &$found ): void {
		$previous = $tokens[$i - 1] ?? null;
		if ( $previous === null || !in_array( $previous->text, [ '(', ',' ], true ) ) {
			return;
		}

		$guard = strtolower( $tokens[$i - 2]->text ?? '' );
		if ( in_array( $guard, [ 'function_exists', 'is_callable', 'defined' ], true ) ) {
			return;
		}

		$name = strtolower( trim( $tokens[$i]->text, '\'"' ) );
		if ( isset( self::FUNCTIONS[$name] ) ) {
			$this->add( $found, $targets, $file, $tokens[$i]->line, self::FUNCTIONS[$name], "'" . $name . "' (as callable)" );
		}
	}

	private function terminatedBySemicolon( array $tokens, int $i ): bool {
		$depth = 0;
		$count = count( $tokens );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$text = $tokens[$j]->text;
			if ( $text === '(' || $text === '[' || $text === '{' ) {
				$depth++;
			} elseif ( $text === ')' || $text === ']' || $text === '}' ) {
				$depth--;
			} elseif ( $depth === 0 && $text === ':' ) {
				return false;
			} elseif ( $depth === 0 && $text === ';' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return int[]
	 */
	private function bodyIndexes( array $tokens, int $from ): array {
		$count = count( $tokens );
		$open = $from;
		while ( $open < $count && $tokens[$open]->text !== '{' && $tokens[$open]->text !== ';' ) {
			$open++;
		}

		if ( $open >= $count || $tokens[$open]->text !== '{' ) {
			return [];
		}

		$indexes = [];
		$depth = 0;
		for ( $j = $open; $j < $count; $j++ ) {
			$token = $tokens[$j];
			if ( $token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES ) {
				$depth++;
			} elseif ( $token->text === '}' ) {
				$depth--;
				if ( $depth === 0 ) {
					break;
				}
			}

			$nested = $token->id === T_FUNCTION && $j > $open ||
				$token->id === T_CLASS && ( $tokens[$j - 1]->id ?? null ) !== T_DOUBLE_COLON;
			if ( $nested ) {
				$j = $this->skipBody( $tokens, $j );
				continue;
			}

			$indexes[] = $j;
		}

		return $indexes;
	}

}

// @codeCoverageIgnoreStart
return FindPhpCompatibilityIssues::class;
// @codeCoverageIgnoreEnd
