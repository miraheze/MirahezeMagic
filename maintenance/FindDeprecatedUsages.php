<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List usages in extensions and skins of removed or hard deprecated MediaWiki code.
 *
 * Usage:
 *     php run.php MirahezeMagic:FindDeprecatedUsages --from-version=current_version --to-version=new_version
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

use Miraheze\MirahezeMagic\Maintenance\Base\VersionComparisonMaintenance;
use PhpToken;
use function array_diff_key;
use function array_filter;
use function array_key_last;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function end;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function json_decode;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strlen;
use function strpos;
use function strrchr;
use function strstr;
use function strtolower;
use function strtoupper;
use function substr;
use function substr_count;
use function usort;
use const PREG_SET_ORDER;
use const T_ABSTRACT;
use const T_AS;
use const T_ATTRIBUTE;
use const T_BOOLEAN_AND;
use const T_BOOLEAN_OR;
use const T_CATCH;
use const T_CLASS;
use const T_CLASS_C;
use const T_COALESCE;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_COLON;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_ENUM;
use const T_EXTENDS;
use const T_FINAL;
use const T_FN;
use const T_FUNC_C;
use const T_FUNCTION;
use const T_IMPLEMENTS;
use const T_INSTANCEOF;
use const T_INTERFACE;
use const T_LOGICAL_AND;
use const T_LOGICAL_OR;
use const T_MATCH;
use const T_METHOD_C;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_NULLSAFE_OBJECT_OPERATOR;
use const T_OBJECT_OPERATOR;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_READONLY;
use const T_STATIC;
use const T_STRING;
use const T_TRAIT;
use const T_USE;
use const T_VAR;
use const T_VARIABLE;
use const T_WHITESPACE;

class FindDeprecatedUsages extends VersionComparisonMaintenance {

	private const array SKIPPED_DIRECTORIES = [ '.git', 'node_modules', 'tests', 'vendor' ];

	private const array CORE_DIRECTORIES = [ 'includes', 'maintenance', 'languages' ];

	private const array BUILTIN_TYPES = [
		'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable', 'object', 'mixed', 'void',
		'null', 'never', 'false', 'true', 'parent',
	];

	private const array MODIFIERS = [ T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY, T_VAR ];

	private const array CLASS_KEYWORDS = [ T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ];

	private const array NAME_TOKENS = [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ];

	private const array CLASS_CONTEXT = [ T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_CATCH ];

	private const array NOT_CONSTANT_CONTEXT = [
		T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST, T_FUNCTION, T_CLASS,
		T_NEW, T_EXTENDS, T_IMPLEMENTS, T_INSTANCEOF, T_NAMESPACE, T_INTERFACE, T_TRAIT, T_ENUM,
	];

	private const string MAIN_CONFIG_NAMES = 'mediawiki\\mainconfignames';

	/** @var array{declared: array<string, array<string, mixed>>, hard: array<string, array<string, array<string, string>>>} */
	private array $fromIndex = [];

	/** @var array{declared: array<string, array<string, mixed>>, hard: array<string, array<string, array<string, string>>>} */
	private array $toIndex = [];

	/** @var array{declared: array<string, array<string, mixed>>, hard: array<string, array<string, array<string, string>>>} */
	private array $localIndex = [];

	private string $droppedTablePattern = '';

	private string $droppedColumnPattern = '';

	private array $droppedTables = [];

	private array $droppedColumns = [];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List usages in extensions and skins of removed or hard deprecated MediaWiki code.' );

		$this->addEnabledOnlyOption();
		$this->addOption( 'new-only', 'Only report code that became hard deprecated between the two versions' );
		$this->addOption( 'extension', 'Only scan the extension or skin with this name or directory', false, true );
	}

	public function execute() {
		$toVersion = $this->getToVersion();
		$this->fromIndex = $this->buildCoreIndex( $this->getFromVersion() );
		$this->toIndex = $this->buildCoreIndex( $toVersion );
		$this->localIndex = $this->newIndex();
		$this->findDroppedSchema( $this->getFromVersion(), $toVersion );

		$base = $this->getVersionPath( $toVersion );
		$all = $this->findManifestFiles( $toVersion );
		$entries = $this->selectEntries( $all );

		foreach ( $this->phpFiles( $all ) as $file ) {
			$code = file_get_contents( $file );
			if ( $code !== false ) {
				$this->parseDeclarations( $code, $this->localIndex );
			}
		}

		$found = [];
		foreach ( $entries as $entry ) {
			$this->scanManifestHooks( $entry, $base, $found );
		}

		$files = $this->phpFiles( $entries );
		foreach ( $files as $file ) {
			$code = file_get_contents( $file );
			if ( $code !== false ) {
				$this->scanFile( $code, substr( $file, strlen( $base ) + 1 ), $found );
			}
		}

		$this->report( $found, count( $files ) );
	}

	private function phpFiles( array $entries ): array {
		$directories = [];
		foreach ( $entries as $entry ) {
			$directories[$entry['path']] = true;
		}

		$files = [];
		foreach ( array_keys( $directories ) as $directory ) {
			foreach ( $this->iterateFiles( $directory, self::SKIPPED_DIRECTORIES ) as $file ) {
				if ( str_ends_with( $file, '.php' ) ) {
					$files[$file] = $file;
				}
			}
		}

		return array_values( $files );
	}

	private function report( array $found, int $fileCount ): void {
		$newOnly = $this->hasOption( 'new-only' );
		$results = array_values( array_filter(
			$found,
			static fn ( array $item ): bool => !$newOnly || $item['status'] === 'REMOVED' || $item['new']
		) );

		usort(
			$results,
			static fn ( array $a, array $b ): int => strcmp( $a['file'], $b['file'] ) ?: $a['line'] <=> $b['line']
		);

		$totals = [];
		foreach ( $results as $item ) {
			$since = $item['since'] !== '' ? ' (since ' . $item['since'] . ')' : '';
			$uses = $item['count'] > 1 ? ' x' . $item['count'] : '';
			$this->output(
				$item['file'] . ':' . $item['line'] . ' ' . $item['status'] . ' ' . $item['kind'] . ' ' . $item['name'] . $since . $uses . "\n"
			);
			$totals[$item['status']] = ( $totals[$item['status']] ?? 0 ) + 1;
		}

		$summary = [];
		foreach ( $totals as $status => $total ) {
			$summary[] = $status . ': ' . $total;
		}

		$this->output( "\nCount: " . count( $results ) . ( $summary ? ' (' . implode( ', ', $summary ) . ')' : '' ) . "\n" );
		$this->output( 'PHP files scanned: ' . $fileCount . "\n" );
	}

	private function selectEntries( array $entries ): array {
		$only = $this->getOption( 'extension' );
		if ( $only === null ) {
			return $entries;
		}

		$only = strtolower( $only );
		return array_values( array_filter(
			$entries,
			static fn ( array $entry ): bool => strtolower( $entry['name'] ) === $only ||
				strtolower( $entry['dir'] ) === $only
		) );
	}

	/**
	 * @return array{declared: array<string, array<string, mixed>>, hard: array<string, array<string, array<string, string>>>}
	 */
	private function newIndex(): array {
		return [
			'declared' => [
				'class' => [], 'function' => [], 'method' => [], 'constant' => [],
				'parents' => [], 'returns' => [], 'props' => [], 'magic' => [], 'alias' => [],
			],
			'hard' => [ 'class' => [], 'function' => [], 'method' => [], 'hook' => [], 'config' => [] ],
		];
	}

	private function buildCoreIndex( string $version ): array {
		$index = $this->newIndex();
		$base = $this->getVersionPath( $version );

		foreach ( self::CORE_DIRECTORIES as $directory ) {
			foreach ( $this->iterateFiles( $base . '/' . $directory, self::SKIPPED_DIRECTORIES ) as $file ) {
				if ( str_ends_with( $file, '.php' ) ) {
					$code = file_get_contents( $file );
					if ( $code !== false ) {
						$this->parseDeclarations( $code, $index );
					}
				}
			}
		}

		$schema = $this->loadCoreSchema( $version );
		foreach ( $schema['config-schema'] ?? [] as $name => $meta ) {
			$message = (string)( $meta['deprecated'] ?? '' );
			if ( preg_match( '/hard[- ]deprecated/i', $message ) ) {
				$index['hard']['config'][$name] = [ 'name' => $name, 'since' => $this->extractSince( $message ) ];
			}
		}

		$hooksFile = $base . '/includes/HookContainer/DeprecatedHooks.php';
		$hooks = is_file( $hooksFile ) ? file_get_contents( $hooksFile ) : '';
		$pattern = "/'([A-Za-z0-9_:.\\\\-]+)'\\s*=>\\s*\\[([^\\]]*)\\]/";
		if ( preg_match_all( $pattern, (string)$hooks, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( str_contains( $match[2], 'deprecatedVersion' ) && !preg_match( "/'silent'\\s*=>\\s*true/", $match[2] ) ) {
					preg_match( "/'deprecatedVersion'\\s*=>\\s*'([0-9.]+)'/", $match[2], $since );
					$index['hard']['hook'][$match[1]] = [ 'name' => $match[1], 'since' => $since[1] ?? '' ];
				}
			}
		}

		return $index;
	}

	private function loadSchema( string $version ): array {
		$base = $this->getVersionPath( $version );
		$directories = [ $base . '/sql' ];
		foreach ( $this->findManifestFiles( $version ) as $entry ) {
			$directories[] = $entry['path'];
		}

		$schema = [];
		foreach ( array_unique( $directories ) as $directory ) {
			foreach ( $this->iterateFiles( $directory, self::SKIPPED_DIRECTORIES ) as $file ) {
				if ( !preg_match( '/(^|\/)tables[^\/]*\.json$/', $file ) ) {
					continue;
				}

				$data = json_decode( (string)file_get_contents( $file ), true );
				if ( !is_array( $data ) ) {
					continue;
				}

				foreach ( $data as $table ) {
					if ( !is_array( $table ) || !isset( $table['name'], $table['columns'] ) || !is_array( $table['columns'] ) ) {
						continue;
					}

					foreach ( $table['columns'] as $column ) {
						if ( isset( $column['name'] ) ) {
							$schema[$table['name']][$column['name']] = true;
						}
					}
				}
			}
		}

		return $schema;
	}

	private function findDroppedSchema( string $fromVersion, string $toVersion ): void {
		$from = $this->loadSchema( $fromVersion );
		$to = $this->loadSchema( $toVersion );

		$toColumns = [];
		foreach ( $to as $columns ) {
			foreach ( array_keys( $columns ) as $column ) {
				$toColumns[strtolower( $column )] = true;
			}
		}

		foreach ( $from as $table => $columns ) {
			if ( !isset( $to[$table] ) ) {
				$this->droppedTables[strtolower( $table )] = $table;
				continue;
			}

			foreach ( array_keys( array_diff_key( $columns, $to[$table] ) ) as $column ) {
				if ( !isset( $toColumns[strtolower( $column )] ) ) {
					$this->droppedColumns[strtolower( $column )] = $table . '.' . $column;
				}
			}
		}

		$quote = static fn ( array $names ): string => implode( '|', array_map(
			static fn ( string $name ): string => preg_quote( $name, '/' ),
			array_keys( $names )
		) );

		if ( $this->droppedTables ) {
			$this->droppedTablePattern = $quote( $this->droppedTables );
		}

		if ( $this->droppedColumns ) {
			$this->droppedColumnPattern = $quote( $this->droppedColumns );
		}
	}

	private function checkSchemaString( string $text, string $file, int $line, array &$found ): void {
		if ( $this->droppedTablePattern !== '' ) {
			$whole = '/^[\'"\s{]*(' . $this->droppedTablePattern . ')(\s+(as\s+)?\w+)?[\'"\s}]*$/i';
			$sql = '/\b(?:from|join|into|update|table)\s+[`{]?(' . $this->droppedTablePattern . ')\b/i';
			if ( preg_match( $whole, $text, $matches ) || preg_match( $sql, $text, $matches ) ) {
				$this->record(
					$found, 'DROPPED', 'TABLE', $this->droppedTables[strtolower( $matches[1] )], '', true, $file, $line
				);
			}
		}

		if ( $this->droppedColumnPattern !== '' ) {
			$pattern = '/(?<![\w])(' . $this->droppedColumnPattern . ')(?!\w)/i';
			if ( preg_match_all( $pattern, $text, $matches ) ) {
				foreach ( $matches[1] as $name ) {
					$this->record(
						$found, 'DROPPED', 'COLUMN', $this->droppedColumns[strtolower( $name )], '', true, $file, $line
					);
				}
			}
		}
	}

	private function extractSince( string $text ): string {
		return preg_match( '/since\s+v?(\d+\.\d+)/i', $text, $matches ) ? $matches[1] : '';
	}

	private function tokenize( string $code ): array {
		return array_values( array_filter(
			PhpToken::tokenize( $code ),
			static fn ( PhpToken $token ): bool => !$token->is( [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ] )
		) );
	}

	private function declaredKey( string $kind, string $key ): string {
		if ( $kind === 'constant' ) {
			$position = strpos( $key, '::' );
			return $position === false ? $key : strtolower( substr( $key, 0, $position ) ) . substr( $key, $position );
		}

		return strtolower( $key );
	}

	private function declare( array &$index, string $kind, string $key ): void {
		$index['declared'][$kind][$this->declaredKey( $kind, $key )] = $key;
	}

	private function markHard( array &$index, string $kind, string $key, string $since ): void {
		$lookup = strtolower( $key );
		if ( !isset( $index['hard'][$kind][$lookup] ) ) {
			$index['hard'][$kind][$lookup] = [ 'name' => $key, 'since' => $since ];
		}
	}

	private function skipAttribute( array $tokens, int $i ): int {
		$depth = 0;
		$count = count( $tokens );
		for ( $j = $i; $j < $count; $j++ ) {
			if ( $tokens[$j]->is( T_ATTRIBUTE ) || $tokens[$j]->text === '[' ) {
				$depth++;
			} elseif ( $tokens[$j]->text === ']' ) {
				$depth--;
				if ( $depth === 0 ) {
					return $j;
				}
			}
		}

		return $count - 1;
	}

	private function stringsInCall( array $tokens, int $open ): array {
		$strings = [];
		$depth = 0;
		$count = count( $tokens );
		for ( $j = $open; $j < $count; $j++ ) {
			if ( $tokens[$j]->text === '(' ) {
				$depth++;
			} elseif ( $tokens[$j]->text === ')' ) {
				$depth--;
				if ( $depth === 0 ) {
					break;
				}
			} elseif ( $tokens[$j]->is( T_CONSTANT_ENCAPSED_STRING ) ) {
				$strings[] = str_replace( '\\\\', '\\', substr( $tokens[$j]->text, 1, -1 ) );
			}
		}

		return $strings;
	}

	private function typeName( string $text, int $id, string $namespace, array $imports, string $class ): string {
		$lower = strtolower( $text );
		if ( in_array( $lower, self::BUILTIN_TYPES, true ) ) {
			return '';
		}

		if ( $lower === 'self' ) {
			return $class;
		}

		if ( $lower === 'static' ) {
			return '*';
		}

		return $this->resolveClass( $text, $id, $namespace, $imports );
	}

	/**
	 * @return array{params: array<string, string>, promoted: array<string, string>, return: string, types: PhpToken[], end: int}
	 */
	private function parseSignature( array $tokens, int $open, string $namespace, array $imports, string $class ): array {
		$result = [ 'params' => [], 'promoted' => [], 'return' => '', 'types' => [], 'end' => $open ];
		$count = count( $tokens );
		$depth = 0;
		$parameter = [];
		$parameters = [];

		for ( $j = $open; $j < $count; $j++ ) {
			$token = $tokens[$j];
			if ( $token->is( T_ATTRIBUTE ) ) {
				$j = $this->skipAttribute( $tokens, $j );
				continue;
			}

			if ( $token->text === '(' || $token->text === '[' ) {
				$depth++;
				if ( $depth === 1 ) {
					continue;
				}
			} elseif ( $token->text === ')' || $token->text === ']' ) {
				$depth--;
				if ( $depth === 0 ) {
					$parameters[] = $parameter;
					$result['end'] = $j;
					break;
				}
			} elseif ( $token->text === ',' && $depth === 1 ) {
				$parameters[] = $parameter;
				$parameter = [];
				continue;
			}

			$parameter[] = $token;
		}

		foreach ( $parameters as $parameter ) {
			$names = [];
			$modifier = false;
			$intersection = false;
			$variable = null;
			/** @phan-suppress-next-line PhanEmptyForeach */
			foreach ( $parameter as $token ) {
				if ( $token->is( self::MODIFIERS ) ) {
					$modifier = true;
				} elseif ( $token->is( T_VARIABLE ) ) {
					$variable = $token->text;
					break;
				} elseif ( $token->is( self::NAME_TOKENS ) ) {
					$names[] = $token;
				} elseif ( $token->text === '&' && $names ) {
					$intersection = true;
				}
			}

			foreach ( $names as $token ) {
				$result['types'][] = $token;
			}

			if ( $variable === null ) {
				continue;
			}

			$type = '';
			if ( $names && !$intersection ) {
				$resolved = [];
				foreach ( $names as $name ) {
					$part = $this->typeName( $name->text, $name->id, $namespace, $imports, $class );
					if ( $part === '' || $part === '*' ) {
						$resolved = [];
						break;
					}

					$resolved[$part] = true;
				}

				$type = implode( '|', array_keys( $resolved ) );
			}

			$result['params'][$variable] = $type;
			if ( $modifier ) {
				$result['promoted'][$variable] = $result['params'][$variable];
			}
		}

		$colon = $tokens[$result['end'] + 1] ?? null;
		if ( $colon && $colon->text === ':' ) {
			$names = [];
			$union = false;
			for ( $j = $result['end'] + 2; $j < $count && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';'; $j++ ) {
				if ( $tokens[$j]->is( self::NAME_TOKENS ) ) {
					$names[] = $tokens[$j];
				} elseif ( $tokens[$j]->text === '|' || $tokens[$j]->text === '&' ) {
					$union = true;
				}
			}

			foreach ( $names as $token ) {
				$result['types'][] = $token;
			}

			if ( count( $names ) === 1 && !$union ) {
				$result['return'] = $this->typeName( $names[0]->text, $names[0]->id, $namespace, $imports, $class );
			}
		}

		return $result;
	}

	/**
	 * @return array<string, string>
	 */
	private function docTypes( string $doc, string $namespace, array $imports, string $class ): array {
		$types = [];
		if ( !preg_match_all( '/@(?:param|var)\s+(\S+)\s+(?:&?\.\.\.)?(\$\w+)/', $doc, $matches, PREG_SET_ORDER ) ) {
			return $types;
		}

		foreach ( $matches as $match ) {
			$parts = array_values( array_filter(
				explode( '|', ltrim( $match[1], '?' ) ),
				static fn ( string $part ): bool => strtolower( $part ) !== 'null' && $part !== ''
			) );

			$resolved = [];
			foreach ( $parts as $part ) {
				if ( !preg_match( '/^\\\\?[A-Za-z_][\w\\\\]*$/', $part ) ) {
					continue 2;
				}

				$id = $part[0] === '\\' ? T_NAME_FULLY_QUALIFIED : ( str_contains( $part, '\\' ) ? T_NAME_QUALIFIED : T_STRING );
				$type = $this->typeName( $part, $id, $namespace, $imports, $class );
				if ( $type === '' || $type === '*' ) {
					continue 2;
				}

				$resolved[$type] = true;
			}

			if ( $resolved ) {
				$types[$match[2]] = implode( '|', array_keys( $resolved ) );
			}
		}

		return $types;
	}

	private function tokenizeWithDocs( string $code ): array {
		$tokens = [];
		$docs = [];
		$pending = '';
		foreach ( PhpToken::tokenize( $code ) as $token ) {
			if ( $token->is( [ T_WHITESPACE, T_COMMENT ] ) ) {
				continue;
			}

			if ( $token->is( T_DOC_COMMENT ) ) {
				$pending = $token->text;
				continue;
			}

			if ( $pending !== '' ) {
				$docs[count( $tokens )] = $pending;
				$pending = '';
			}

			$tokens[] = $token;
		}

		return [ $tokens, $docs ];
	}

	private function matchBackward( array $tokens, int $close ): int {
		$depth = 0;
		for ( $j = $close; $j >= 0; $j-- ) {
			$text = $tokens[$j]->text;
			if ( $text === ')' || $text === ']' ) {
				$depth++;
			} elseif ( $text === '(' || $text === '[' ) {
				$depth--;
				if ( $depth === 0 ) {
					return $j;
				}
			}
		}

		return -1;
	}

	/**
	 * @return array{status: string, class: string}
	 */
	private function findMember( array $core, string $kind, string $class, string $member, array &$visited = [] ): array {
		$lower = strtolower( ltrim( $class, '\\' ) );
		if ( isset( $visited[$lower] ) ) {
			return [ 'status' => 'missing', 'class' => '' ];
		}

		$visited[$lower] = true;
		$known = isset( $core['declared']['class'][$lower] ) || isset( $this->localIndex['declared']['class'][$lower] );
		if ( !$known ) {
			return [ 'status' => 'external', 'class' => '' ];
		}

		if ( isset( $core['declared']['alias'][$lower] ) || isset( $this->localIndex['declared']['alias'][$lower] ) ) {
			return [ 'status' => 'unknown', 'class' => '' ];
		}

		$key = $lower . '::' . ( $kind === 'method' ? strtolower( $member ) : $member );
		if ( isset( $core['declared'][$kind][$key] ) || isset( $this->localIndex['declared'][$kind][$key] ) ) {
			return [ 'status' => 'found', 'class' => $lower ];
		}

		if ( $kind === 'method' && ( isset( $core['declared']['magic'][$lower] ) || isset( $this->localIndex['declared']['magic'][$lower] ) ) ) {
			return [ 'status' => 'unknown', 'class' => '' ];
		}

		$status = 'missing';
		$parents = array_merge( $core['declared']['parents'][$lower] ?? [], $this->localIndex['declared']['parents'][$lower] ?? [] );
		foreach ( $parents as $parent ) {
			$result = $this->findMember( $core, $kind, $parent, $member, $visited );
			if ( $result['status'] === 'found' ) {
				return $result;
			}

			if ( $result['status'] === 'unknown' ) {
				$status = 'unknown';
			} elseif ( $result['status'] === 'external' && $status === 'missing' ) {
				$status = 'external';
			}
		}

		return [ 'status' => $status, 'class' => '' ];
	}

	private function removedMember( string $kind, string $class, string $member ): string {
		$lower = strtolower( ltrim( $class, '\\' ) );
		$to = $this->findMember( $this->toIndex, $kind, $lower, $member );
		if ( $to['status'] !== 'missing' && $to['status'] !== 'external' ) {
			return '';
		}

		$from = $this->findMember( $this->fromIndex, $kind, $lower, $member );
		if ( $from['status'] !== 'found' ) {
			return '';
		}

		return ( $this->fromIndex['declared']['class'][$from['class']] ?? $from['class'] ) . '::' . $member;
	}

	private function checkMember( string $kind, string $class, string $member, string $file, int $line, array &$found ): void {
		$lower = strtolower( ltrim( $class, '\\' ) );
		$to = $this->findMember( $this->toIndex, $kind, $lower, $member );
		if ( $to['status'] === 'found' ) {
			if ( $kind === 'method' ) {
				$this->checkHard( 'method', $to['class'] . '::' . strtolower( $member ), $file, $line, $found );
			}

			return;
		}

		$name = $this->removedMember( $kind, $lower, $member );
		if ( $name !== '' ) {
			$this->record( $found, 'REMOVED', strtoupper( $kind ), $name, '', true, $file, $line );
		}
	}

	private function checkReceiver( string $receiver, string $method, string $file, int $line, array &$found ): void {
		if ( !str_contains( $receiver, '|' ) ) {
			$this->checkMember( 'method', $receiver, $method, $file, $line, $found );
			return;
		}

		$name = '';
		foreach ( explode( '|', $receiver ) as $class ) {
			$removed = $this->removedMember( 'method', $class, $method );
			if ( $removed === '' ) {
				return;
			}

			$name = $name === '' ? $removed : $name;
		}

		$this->record( $found, 'REMOVED', 'METHOD', $name, '', true, $file, $line );
	}

	private function propertyType( string $class, string $property, array &$visited = [] ): string {
		$lower = strtolower( ltrim( $class, '\\' ) );
		if ( isset( $visited[$lower] ) ) {
			return '';
		}

		$visited[$lower] = true;
		$local = $this->localIndex['declared']['props'][$lower][$property] ?? '';
		if ( $local !== '' ) {
			return $local;
		}

		$core = $this->toIndex['declared']['props'][$lower][$property] ?? '';
		if ( $core !== '' ) {
			return $core;
		}

		$parents = array_merge(
			$this->localIndex['declared']['parents'][$lower] ?? [],
			$this->toIndex['declared']['parents'][$lower] ?? []
		);
		foreach ( $parents as $parent ) {
			$type = $this->propertyType( $parent, $property, $visited );
			if ( $type !== '' ) {
				return $type;
			}
		}

		return '';
	}

	private function returnType( string $class, string $method ): string {
		$member = $this->findMember( $this->toIndex, 'method', $class, $method );
		if ( $member['status'] !== 'found' ) {
			return '';
		}

		$key = $member['class'] . '::' . strtolower( $method );
		$type = $this->localIndex['declared']['returns'][$key] ?? $this->toIndex['declared']['returns'][$key] ?? '';
		return $type === '' ? '' : ( $type === '*' ? $class : $type );
	}

	private function specialClass( string $text, int $id, array $context ): string {
		return match ( strtolower( $text ) ) {
			'self', 'static' => $context['self'],
			'parent' => $context['parent'],
			default => $this->resolveClass( $text, $id, $context['namespace'], $context['imports'] ),
		};
	}

	private function typeOf( array $tokens, int $end, array $context ): string {
		$token = $tokens[$end] ?? null;
		if ( !$token ) {
			return '';
		}

		if ( $token->is( T_VARIABLE ) ) {
			return $token->text === '$this' ? $context['self'] : ( $context['vars'][$token->text] ?? '' );
		}

		$before = $tokens[$end - 1] ?? null;
		if ( $token->is( self::NAME_TOKENS ) ) {
			if ( $before && $before->is( T_NEW ) ) {
				return $this->specialClass( $token->text, $token->id, $context );
			}

			if ( $before && $before->is( [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ] ) ) {
				$receiver = $this->typeOf( $tokens, $end - 2, $context );
				return $receiver === '' ? '' : $this->propertyType( $receiver, '$' . $token->text );
			}

			return '';
		}

		if ( $token->text !== ')' ) {
			return '';
		}

		$open = $this->matchBackward( $tokens, $end );
		if ( $open < 1 ) {
			return '';
		}

		$name = $tokens[$open - 1];
		if ( !$name->is( self::NAME_TOKENS ) ) {
			$inner = $tokens[$open + 1] ?? null;
			return $inner && $inner->is( T_NEW ) && isset( $tokens[$open + 2] )
				? $this->specialClass( $tokens[$open + 2]->text, $tokens[$open + 2]->id, $context )
				: '';
		}

		$operator = $tokens[$open - 2] ?? null;
		if ( !$operator ) {
			return '';
		}

		if ( $operator->is( T_NEW ) ) {
			return $this->specialClass( $name->text, $name->id, $context );
		}

		if ( $operator->is( [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ] ) ) {
			$receiver = $this->typeOf( $tokens, $open - 3, $context );
			return $receiver === '' ? '' : $this->returnType( $receiver, $name->text );
		}

		if ( $operator->is( T_DOUBLE_COLON ) && isset( $tokens[$open - 3] ) && $tokens[$open - 3]->is( self::NAME_TOKENS ) ) {
			$class = $this->specialClass( $tokens[$open - 3]->text, $tokens[$open - 3]->id, $context );
			return $class === '' ? '' : $this->returnType( $class, $name->text );
		}

		return '';
	}

	private function parseDeclarations( string $code, array &$index ): void {
		$tokens = $this->tokenize( $code );
		$count = count( $tokens );
		$namespace = '';
		$imports = [];
		$functionImports = [];
		$constImports = [];
		$depth = 0;
		$classes = [];
		$functions = [];
		$pendingClass = null;
		$pendingFunction = null;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[$i];
			$prev = $tokens[$i - 1] ?? null;
			$next = $tokens[$i + 1] ?? null;

			if ( $token->is( T_ATTRIBUTE ) ) {
				$i = $this->skipAttribute( $tokens, $i );
				continue;
			}

			if ( $token->text === '{' || $token->is( [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ] ) ) {
				$depth++;
				if ( $pendingClass !== null ) {
					$classes[] = [ 'name' => $pendingClass, 'depth' => $depth ];
					$pendingClass = null;
				} elseif ( $pendingFunction !== null ) {
					$functions[] = $pendingFunction + [ 'depth' => $depth ];
					$pendingFunction = null;
				}

				continue;
			}

			if ( $token->text === '}' ) {
				if ( $classes && end( $classes )['depth'] === $depth ) {
					array_pop( $classes );
				}

				if ( $functions && end( $functions )['depth'] === $depth ) {
					array_pop( $functions );
				}

				$depth--;
				continue;
			}

			if ( $token->text === ';' ) {
				$pendingFunction = null;
				continue;
			}

			if ( $token->is( T_NAMESPACE ) && $next && $next->is( [ T_STRING, T_NAME_QUALIFIED ] ) ) {
				$namespace = $next->text;
				$imports = [];
				$i++;
				continue;
			}

			$inClass = $classes && ( !$functions || end( $functions )['depth'] < end( $classes )['depth'] );

			if ( $token->is( T_USE ) ) {
				if ( $next && $next->text === '(' ) {
					continue;
				}

				if ( $classes ) {
					if ( $inClass ) {
						$owner = strtolower( end( $classes )['name'] );
						for ( $j = $i + 1; $j < $count && $tokens[$j]->text !== ';' && $tokens[$j]->text !== '{'; $j++ ) {
							if ( $tokens[$j]->is( self::NAME_TOKENS ) ) {
								$index['declared']['parents'][$owner][] = $this->resolveClass(
									$tokens[$j]->text, $tokens[$j]->id, $namespace, $imports
								);
							}
						}

						$i = $j - 1;
					}

					continue;
				}

				$entries = [];
				$i = $this->parseImport( $tokens, $i, $imports, $functionImports, $constImports, $entries );
				continue;
			}

			if ( $token->is( self::CLASS_KEYWORDS ) ) {
				if ( !( $prev && $prev->is( [ T_DOUBLE_COLON, T_NEW ] ) ) && $next && $next->is( T_STRING ) ) {
					$name = ( $namespace !== '' ? $namespace . '\\' : '' ) . $next->text;
					$this->declare( $index, 'class', $name );

					$parents = [];
					for ( $j = $i + 2; $j < $count && $tokens[$j]->text !== '{'; $j++ ) {
						if ( $tokens[$j]->is( self::NAME_TOKENS ) && !in_array( strtolower( $tokens[$j]->text ), [ 'string', 'int' ], true ) ) {
							$parents[] = $this->resolveClass( $tokens[$j]->text, $tokens[$j]->id, $namespace, $imports );
						}
					}

					$index['declared']['parents'][strtolower( $name )] = $parents;
					$pendingClass = $name;
					$i++;
				}

				continue;
			}

			if ( $token->is( T_FUNCTION ) ) {
				if ( $prev && $prev->is( T_USE ) ) {
					continue;
				}

				$j = $i + 1;
				if ( isset( $tokens[$j] ) && $tokens[$j]->text === '&' ) {
					$j++;
				}

				if ( isset( $tokens[$j] ) && $tokens[$j]->is( T_STRING ) ) {
					$name = $tokens[$j]->text;
					if ( $inClass ) {
						$kind = 'method';
						$owner = end( $classes )['name'];
						$key = $owner . '::' . $name;
					} else {
						$kind = 'function';
						$owner = '';
						$key = ( $namespace !== '' ? $namespace . '\\' : '' ) . $name;
					}

					$this->declare( $index, $kind, $key );
					if ( $kind === 'method' && isset( $tokens[$j + 1] ) && $tokens[$j + 1]->text === '(' ) {
						$lowerOwner = strtolower( $owner );
						$signature = $this->parseSignature( $tokens, $j + 1, $namespace, $imports, $owner );
						$index['declared']['returns'][$lowerOwner . '::' . strtolower( $name )] = $signature['return'];
						foreach ( $signature['promoted'] as $variable => $type ) {
							if ( $type !== '' ) {
								$index['declared']['props'][$lowerOwner][$variable] = $type;
							}
						}

						if ( in_array( strtolower( $name ), [ '__call', '__callstatic' ], true ) ) {
							$index['declared']['magic'][$lowerOwner] = true;
						}
					}

					$pendingFunction = [ 'kind' => $kind, 'key' => $key ];
					$i = $j;
				}

				continue;
			}

			if ( $token->is( T_CONST ) ) {
				if ( $prev && $prev->is( T_USE ) ) {
					continue;
				}

				for ( $j = $i + 1; $j < $count && $tokens[$j]->text !== ';'; $j++ ) {
					if ( $tokens[$j]->is( T_STRING ) && isset( $tokens[$j + 1] ) && $tokens[$j + 1]->text === '=' ) {
						$owner = $classes ? end( $classes )['name'] . '::' : ( $namespace !== '' ? $namespace . '\\' : '' );
						$this->declare( $index, 'constant', $owner . $tokens[$j]->text );
						break;
					}
				}

				continue;
			}

			if ( $token->is( T_VARIABLE ) ) {
				if ( $inClass && $pendingFunction === null && $prev && $prev->is( self::NAME_TOKENS ) ) {
					$class = end( $classes )['name'];
					$type = $this->typeName( $prev->text, $prev->id, $namespace, $imports, $class );
					if ( $type !== '' && $type !== '*' ) {
						$index['declared']['props'][strtolower( $class )][$token->text] = $type;
					}
				}

				continue;
			}

			if ( !$token->is( [ T_STRING, T_NAME_FULLY_QUALIFIED ] ) || !$next || $next->text !== '(' ) {
				continue;
			}

			$called = strtolower( ltrim( $token->text, '\\' ) );
			if ( $called === 'class_alias' ) {
				$strings = $this->stringsInCall( $tokens, $i + 1 );
				if ( $strings ) {
					$alias = end( $strings );
					$this->declare( $index, 'class', $alias );
					$index['declared']['alias'][strtolower( $alias )] = true;
				}
			} elseif ( $called === 'define' ) {
				$strings = $this->stringsInCall( $tokens, $i + 1 );
				if ( $strings ) {
					$this->declare( $index, 'constant', $strings[0] );
				}
			} elseif ( $called === 'wfdeprecated' && $functions && isset( $tokens[$i + 3] ) && $tokens[$i + 3]->text === ',' ) {
				$first = $tokens[$i + 2];
				$since = '';
				foreach ( $this->stringsInCall( $tokens, $i + 1 ) as $string ) {
					if ( preg_match( '/^(\d+\.\d+)/', $string, $matches ) ) {
						$since = $matches[1];
						break;
					}
				}

				$current = end( $functions );
				if ( $first->is( [ T_METHOD_C, T_FUNC_C ] ) ) {
					$this->markHard( $index, $current['kind'], $current['key'], $since );
				} elseif ( $first->is( T_CLASS_C ) && $classes ) {
					$this->markHard( $index, 'class', end( $classes )['name'], $since );
				}
			}
		}
	}

	private function record( array &$found, string $status, string $kind, string $name, string $since, bool $new, string $file, int $line ): void {
		$key = $file . '|' . $status . '|' . $kind . '|' . $name;
		if ( isset( $found[$key] ) ) {
			$found[$key]['count']++;
			return;
		}

		$found[$key] = [
			'file' => $file,
			'line' => $line,
			'status' => $status,
			'kind' => $kind,
			'name' => $name,
			'since' => $since,
			'new' => $new,
			'count' => 1,
		];
	}

	private function checkHard( string $kind, string $lookup, string $file, int $line, array &$found ): bool {
		$entry = $this->toIndex['hard'][$kind][$lookup] ?? null;
		if ( $entry === null ) {
			return false;
		}

		$this->record(
			$found, 'DEPRECATED', strtoupper( $kind ), $entry['name'], $entry['since'],
			!isset( $this->fromIndex['hard'][$kind][$lookup] ), $file, $line
		);
		return true;
	}

	private function checkRemoved( string $kind, string $lookup, string $file, int $line, array &$found ): bool {
		$name = $this->fromIndex['declared'][$kind][$lookup] ?? null;
		if ( $name === null || isset( $this->toIndex['declared'][$kind][$lookup] ) ) {
			return false;
		}

		$this->record( $found, 'REMOVED', strtoupper( $kind ), $name, '', true, $file, $line );
		return true;
	}

	private function scanManifestHooks( array $entry, string $base, array &$found ): void {
		$hooks = $entry['info']['Hooks'] ?? [];
		if ( !is_array( $hooks ) ) {
			return;
		}

		$text = file_get_contents( $entry['file'] ) ?: '';
		foreach ( array_keys( $hooks ) as $hook ) {
			if ( isset( $this->toIndex['hard']['hook'][$hook] ) ) {
				$position = strpos( $text, '"' . $hook . '"' );
				$line = $position === false ? 1 : substr_count( $text, "\n", 0, $position ) + 1;
				$this->checkHard( 'hook', $hook, substr( $entry['file'], strlen( $base ) + 1 ), $line, $found );
			}
		}
	}

	private function resolveClass( string $name, int $type, string $namespace, array $imports ): string {
		if ( $type === T_NAME_FULLY_QUALIFIED ) {
			return ltrim( $name, '\\' );
		}

		if ( $type === T_NAME_RELATIVE ) {
			return ( $namespace !== '' ? $namespace . '\\' : '' ) . substr( $name, strlen( 'namespace\\' ) );
		}

		$first = strstr( $name, '\\', true );
		$alias = strtolower( $first === false ? $name : $first );
		if ( isset( $imports[$alias] ) ) {
			return $imports[$alias] . ( $first === false ? '' : substr( $name, strlen( $first ) ) );
		}

		return ( $namespace !== '' ? $namespace . '\\' : '' ) . $name;
	}

	private function checkClass( string $name, string $file, int $line, array &$found ): void {
		$lookup = strtolower( ltrim( $name, '\\' ) );
		if ( !$this->checkHard( 'class', $lookup, $file, $line, $found ) ) {
			$this->checkRemoved( 'class', $lookup, $file, $line, $found );
		}
	}

	private function checkFunction( array $candidates, string $file, int $line, array &$found ): void {
		foreach ( $candidates as $candidate ) {
			$lookup = strtolower( $candidate );
			if ( $this->checkHard( 'function', $lookup, $file, $line, $found ) || $this->checkRemoved( 'function', $lookup, $file, $line, $found ) ) {
				return;
			}
		}
	}

	private function checkConfig( string $name, string $file, int $line, array &$found ): void {
		if ( isset( $this->toIndex['hard']['config'][$name] ) ) {
			$entry = $this->toIndex['hard']['config'][$name];
			$this->record(
				$found, 'DEPRECATED', 'CONFIG', $entry['name'], $entry['since'],
				!isset( $this->fromIndex['hard']['config'][$name] ), $file, $line
			);
		}
	}

	private function parseImport(
		array $tokens,
		int $i,
		array &$imports,
		array &$functionImports,
		array &$constImports,
		array &$entries
	): int {
		$count = count( $tokens );
		$type = 'class';
		$j = $i + 1;
		if ( $tokens[$j]->is( T_FUNCTION ) ) {
			$type = 'function';
			$j++;
		} elseif ( $tokens[$j]->is( T_CONST ) ) {
			$type = 'const';
			$j++;
		}

		$prefix = '';
		$entryType = $type;
		for ( ; $j < $count && $tokens[$j]->text !== ';'; $j++ ) {
			$token = $tokens[$j];
			if ( $token->is( T_FUNCTION ) ) {
				$entryType = 'function';
			} elseif ( $token->is( T_CONST ) ) {
				$entryType = 'const';
			} elseif ( $token->text === ',' || $token->text === '}' ) {
				$entryType = $type;
			} elseif ( $token->is( [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ] ) ) {
				$name = ltrim( $token->text, '\\' );
				if ( isset( $tokens[$j + 1] ) && $tokens[$j + 1]->is( T_NS_SEPARATOR ) ) {
					$prefix = $name . '\\';
					$j += 2;
					continue;
				}

				$fq = $prefix . $name;
				$alias = substr( strrchr( '\\' . $fq, '\\' ), 1 );
				if ( isset( $tokens[$j + 1] ) && $tokens[$j + 1]->is( T_AS ) && isset( $tokens[$j + 2] ) ) {
					$alias = $tokens[$j + 2]->text;
					$j += 2;
				}

				if ( $entryType === 'function' ) {
					$functionImports[strtolower( $alias )] = $fq;
				} elseif ( $entryType === 'const' ) {
					$constImports[$alias] = $fq;
				} else {
					$imports[strtolower( $alias )] = $fq;
				}

				$entries[] = [ $entryType, $fq, $token->line ];
			}
		}

		return $j;
	}

	private function expressionEnd( array $tokens, int $start ): int {
		$depth = 0;
		$count = count( $tokens );
		for ( $j = $start; $j < $count; $j++ ) {
			$token = $tokens[$j];
			if ( $token->text === '(' || $token->text === '[' || $token->text === '{' ) {
				$depth++;
			} elseif ( $token->text === ')' || $token->text === ']' || $token->text === '}' ) {
				$depth--;
				if ( $depth < 0 ) {
					return $j;
				}
			} elseif ( $depth === 0 ) {
				if ( $token->text === ';' || $token->text === ',' ) {
					return $j;
				}

				if (
					$token->is( [ T_COALESCE, T_BOOLEAN_AND, T_BOOLEAN_OR, T_LOGICAL_AND, T_LOGICAL_OR, T_INSTANCEOF, T_MATCH, T_FUNCTION, T_FN ] ) ||
					in_array( $token->text, [ '?', ':', '.', '+', '-', '*', '/', '|', '&', '=' ], true )
				) {
					return $start;
				}
			}
		}

		return $start;
	}

	private function scanFile( string $code, string $file, array &$found ): void {
		[ $tokens, $docs ] = $this->tokenizeWithDocs( $code );
		$count = count( $tokens );

		$namespace = '';
		$imports = [];
		$functionImports = [];
		$constImports = [];
		$depth = 0;
		$classes = [];
		$pendingClass = null;
		$pendingParent = null;
		$pendingName = '';
		$scopes = [ [ 'depth' => 0, 'vars' => [] ] ];
		$pendingScope = null;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[$i];
			$prev = $tokens[$i - 1] ?? null;
			$next = $tokens[$i + 1] ?? null;

			if ( $token->text === '{' || $token->is( [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ] ) ) {
				$depth++;
				if ( $pendingClass !== null ) {
					$classes[] = [ 'parent' => $pendingParent, 'depth' => $depth, 'name' => $pendingName ];
					$pendingClass = null;
					$pendingParent = null;
				} elseif ( $pendingScope !== null ) {
					$scopes[] = [ 'depth' => $depth, 'vars' => $pendingScope ];
					$pendingScope = null;
				}

				continue;
			}

			if ( $token->text === '}' ) {
				if ( $classes && end( $classes )['depth'] === $depth ) {
					array_pop( $classes );
				}

				if ( count( $scopes ) > 1 && end( $scopes )['depth'] === $depth ) {
					array_pop( $scopes );
				}

				$depth--;
				continue;
			}

			if ( $token->text === ';' ) {
				$pendingScope = null;
				continue;
			}

			if ( $token->is( T_NAMESPACE ) && $next && $next->is( [ T_STRING, T_NAME_QUALIFIED ] ) ) {
				$namespace = $next->text;
				$i++;
				continue;
			}

			if ( $token->is( T_ATTRIBUTE ) ) {
				continue;
			}

			$top = array_key_last( $scopes );
			$context = [
				'vars' => $scopes[$top]['vars'],
				'self' => $classes ? end( $classes )['name'] : '',
				'parent' => $classes ? ( end( $classes )['parent'] ?? '' ) : '',
				'namespace' => $namespace,
				'imports' => $imports,
			];

			if ( $token->is( T_USE ) ) {
				if ( $next && $next->text === '(' ) {
					continue;
				}

				if ( $classes ) {
					for ( $j = $i + 1; $j < $count && $tokens[$j]->text !== ';' && $tokens[$j]->text !== '{'; $j++ ) {
						if ( $tokens[$j]->is( self::NAME_TOKENS ) ) {
							$this->checkClass(
								$this->resolveClass( $tokens[$j]->text, $tokens[$j]->id, $namespace, $imports ),
								$file,
								$tokens[$j]->line,
								$found
							);
						}
					}

					$i = $j - 1;
					continue;
				}

				$entries = [];
				$i = $this->parseImport( $tokens, $i, $imports, $functionImports, $constImports, $entries );
				foreach ( $entries as [ $type, $fq, $line ] ) {
					if ( $type === 'class' ) {
						$this->checkClass( $fq, $file, $line, $found );
					} elseif ( $type === 'function' ) {
						$this->checkFunction( [ $fq ], $file, $line, $found );
					} else {
						$this->checkRemoved( 'constant', $fq, $file, $line, $found );
					}
				}

				continue;
			}

			if ( $token->is( self::CLASS_KEYWORDS ) ) {
				if ( !( $prev && $prev->is( [ T_DOUBLE_COLON, T_NEW ] ) ) && $next && $next->is( T_STRING ) ) {
					$pendingClass = $next->text;
					$pendingName = ( $namespace !== '' ? $namespace . '\\' : '' ) . $next->text;
					$pendingParent = null;
					$i++;
				}

				continue;
			}

			if ( $token->is( T_FUNCTION ) ) {
				$j = $i + 1;
				if ( isset( $tokens[$j] ) && $tokens[$j]->text === '&' ) {
					$j++;
				}

				$named = isset( $tokens[$j] ) && $tokens[$j]->is( T_STRING );
				if ( $named ) {
					$j++;
				}

				if ( isset( $tokens[$j] ) && $tokens[$j]->text === '(' ) {
					$signature = $this->parseSignature( $tokens, $j, $namespace, $imports, $context['self'] );
					foreach ( $signature['types'] as $type ) {
						if ( !in_array( strtolower( $type->text ), [ 'self', 'static', 'parent' ], true ) ) {
							$this->checkClass(
								$this->resolveClass( $type->text, $type->id, $namespace, $imports ), $file, $type->line, $found
							);
						}
					}

					$start = $i;
					while ( $start > 0 && $tokens[$start - 1]->is( self::MODIFIERS ) ) {
						$start--;
					}

					$vars = $signature['params'];
					if ( isset( $docs[$start] ) ) {
						$vars += $this->docTypes( $docs[$start], $namespace, $imports, $context['self'] );
					}

					$pendingScope = $named ? $vars : $vars + $context['vars'];
				}

				continue;
			}

			if ( $token->is( T_CATCH ) ) {
				$names = [];
				$variable = null;
				for ( $j = $i + 1; $j < $count && $tokens[$j]->text !== '{'; $j++ ) {
					if ( $tokens[$j]->is( self::NAME_TOKENS ) ) {
						$names[] = $tokens[$j];
						$this->checkClass(
							$this->resolveClass( $tokens[$j]->text, $tokens[$j]->id, $namespace, $imports ),
							$file,
							$tokens[$j]->line,
							$found
						);
					} elseif ( $tokens[$j]->is( T_VARIABLE ) ) {
						$variable = $tokens[$j]->text;
					}
				}

				if ( $variable !== null && count( $names ) === 1 ) {
					$scopes[$top]['vars'][$variable] = $this->resolveClass(
						$names[0]->text, $names[0]->id, $namespace, $imports
					);
				}

				$i = $j - 1;
				continue;
			}

			if ( $token->is( T_VARIABLE ) ) {
				if ( str_starts_with( $token->text, '$wg' ) ) {
					$this->checkConfig( substr( $token->text, 3 ), $file, $token->line, $found );
				}

				if (
					$token->text === '$wgHooks' &&
					$next &&
					$next->text === '[' &&
					isset( $tokens[$i + 2] ) &&
					$tokens[$i + 2]->is( T_CONSTANT_ENCAPSED_STRING )
				) {
					$this->checkHard( 'hook', substr( $tokens[$i + 2]->text, 1, -1 ), $file, $token->line, $found );
				}

				if ( $classes && $pendingScope === null && count( $scopes ) === 1 && $prev && $prev->is( self::NAME_TOKENS ) ) {
					$this->checkClass( $this->resolveClass( $prev->text, $prev->id, $namespace, $imports ), $file, $prev->line, $found );
				}

				if ( $next && $next->text === '=' && $token->text !== '$this' ) {
					$type = '';
					if ( isset( $docs[$i] ) ) {
						$type = $this->docTypes( $docs[$i], $namespace, $imports, $context['self'] )[$token->text] ?? '';
					}

					$end = $this->expressionEnd( $tokens, $i + 2 );
					if ( $type === '' && $end > $i + 2 ) {
						$type = $this->typeOf( $tokens, $end - 1, $context );
					}

					if ( $type === '' || $type === '*' ) {
						unset( $scopes[$top]['vars'][$token->text] );
					} else {
						$scopes[$top]['vars'][$token->text] = $type;
					}
				}

				continue;
			}

			if ( $token->is( T_ENCAPSED_AND_WHITESPACE ) ) {
				$this->checkSchemaString( $token->text, $file, $token->line, $found );
				continue;
			}

			if ( $token->is( T_CONSTANT_ENCAPSED_STRING ) ) {
				$text = substr( $token->text, 1, -1 );
				$this->checkSchemaString( $text, $file, $token->line, $found );
				if ( str_starts_with( $text, 'wg' ) ) {
					$this->checkConfig( substr( $text, 2 ), $file, $token->line, $found );
				}

				continue;
			}

			if ( !$token->is( self::NAME_TOKENS ) ) {
				continue;
			}

			if ( $prev && $prev->is( [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ] ) ) {
				$lower = strtolower( $token->text );
				if ( $next && $next->text === '(' ) {
					$receiver = $this->typeOf( $tokens, $i - 2, $context );
					if ( $receiver !== '' && $receiver !== '*' ) {
						$this->checkReceiver( $receiver, $token->text, $file, $token->line, $found );
					}
				} elseif ( $this->droppedColumnPattern !== '' && isset( $this->droppedColumns[$lower] ) ) {
					$this->record( $found, 'DROPPED', 'COLUMN', $this->droppedColumns[$lower], '', true, $file, $token->line );
				}

				continue;
			}

			if ( $prev && $prev->is( [ T_DOUBLE_COLON, T_FUNCTION, T_CONST, T_NAMESPACE, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM ] ) ) {
				continue;
			}

			$text = $token->text;
			$type = $token->id;

			if ( $prev && $prev->is( T_NEW ) ) {
				$this->checkClass( $this->resolveClass( $text, $type, $namespace, $imports ), $file, $token->line, $found );
				continue;
			}

			if ( $prev && $prev->is( self::CLASS_CONTEXT ) ) {
				$resolved = $this->resolveClass( $text, $type, $namespace, $imports );
				if ( $prev->is( T_EXTENDS ) && $pendingClass !== null ) {
					$pendingParent = $resolved;
				}

				$this->checkClass( $resolved, $file, $token->line, $found );
				continue;
			}

			if ( $next && $next->text === '(' ) {
				if ( $type === T_STRING ) {
					$candidates = isset( $functionImports[strtolower( $text )] )
						? [ $functionImports[strtolower( $text )] ]
						: [ ...( $namespace !== '' ? [ $namespace . '\\' . $text ] : [] ), $text ];
				} elseif ( $type === T_NAME_FULLY_QUALIFIED ) {
					$candidates = [ ltrim( $text, '\\' ) ];
				} else {
					$candidates = [ $this->resolveClass( $text, $type, $namespace, $imports ) ];
				}

				$this->checkFunction( $candidates, $file, $token->line, $found );
				continue;
			}

			if ( $next && $next->is( T_DOUBLE_COLON ) ) {
				$resolved = $this->specialClass( $text, $type, $context );
				if ( $resolved === '' ) {
					continue;
				}

				$lowerText = strtolower( $text );
				if ( $lowerText !== 'self' && $lowerText !== 'static' && $lowerText !== 'parent' ) {
					$this->checkClass( $resolved, $file, $token->line, $found );
				}

				$member = $tokens[$i + 2] ?? null;
				if ( !$member || !$member->is( T_STRING ) || $member->text === 'class' ) {
					continue;
				}

				if ( isset( $tokens[$i + 3] ) && $tokens[$i + 3]->text === '(' ) {
					$this->checkMember( 'method', $resolved, $member->text, $file, $token->line, $found );
				} else {
					$this->checkMember( 'constant', $resolved, $member->text, $file, $token->line, $found );
					if ( strtolower( ltrim( $resolved, '\\' ) ) === self::MAIN_CONFIG_NAMES ) {
						$this->checkConfig( $member->text, $file, $token->line, $found );
					}
				}

				continue;
			}

			if ( $type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED ) {
				$this->checkClass( $this->resolveClass( $text, $type, $namespace, $imports ), $file, $token->line, $found );
				continue;
			}

			if ( $type === T_STRING && !( $prev && $prev->is( self::NOT_CONSTANT_CONTEXT ) ) ) {
				$this->checkRemoved( 'constant', $constImports[$text] ?? $text, $file, $token->line, $found );
			}
		}
	}
}

// @codeCoverageIgnoreStart
return FindDeprecatedUsages::class;
// @codeCoverageIgnoreEnd
