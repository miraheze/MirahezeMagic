<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/** Temporary: will be removed once 1.47 is deployed everywhere and all issues here are fixed */

use Miraheze\MirahezeMagic\Maintenance\Base\VersionComparisonMaintenance;
use PhpToken;
use function array_filter;
use function array_keys;
use function array_pop;
use function array_values;
use function count;
use function end;
use function file_get_contents;
use function implode;
use function in_array;
use function ltrim;
use function str_contains;
use function str_ends_with;
use function strcmp;
use function strlen;
use function strrchr;
use function strrpos;
use function strstr;
use function strtolower;
use function substr;
use function usort;
use const T_AS;
use const T_CLASS;
use const T_COMMENT;
use const T_CONST;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_COLON;
use const T_EXTENDS;
use const T_FUNCTION;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NAMESPACE;
use const T_NEW;
use const T_NS_SEPARATOR;
use const T_STRING;
use const T_USE;
use const T_WHITESPACE;

class FindMissingGetRestriction extends VersionComparisonMaintenance {

	private const array SKIPPED_DIRECTORIES = [ '.git', '.phan', 'node_modules', 'stubs', 'tests', 'vendor' ];

	private const string ROOT_CLASS = 'specialpage';

	private const array EMPTY_VALUES = [ '', "''", '""', 'null', 'false' ];

	private const array NAME_TOKENS = [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ];

	private array $classes = [];

	private array $shortNames = [];

	private array $instantiations = [];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List special pages that pass a restriction to the SpecialPage constructor without overriding getRestriction().' );

		$this->addEnabledOnlyOption();
		$this->addOption( 'extension', 'Only scan the extension or skin with this name or directory', false, true );
	}

	public function execute() {
		$version = $this->getToVersion();
		$base = $this->getVersionPath( $version );
		$only = $this->getOption( 'extension' );
		$only = $only === null ? null : strtolower( $only );

		$paths = [];
		foreach ( $this->findManifestFiles( $version ) as $entry ) {
			if ( $only === null || strtolower( $entry['name'] ) === $only || strtolower( $entry['dir'] ) === $only ) {
				$paths[$entry['path']] = true;
			}
		}

		foreach ( [ 'includes', 'languages', 'maintenance' ] as $directory ) {
			$this->scanDirectory( $base . '/' . $directory, $base, false );
		}

		foreach ( array_keys( $paths ) as $path ) {
			$this->scanDirectory( $path, $base, true );
		}

		$results = [];
		foreach ( $this->classes as $key => $class ) {
			if ( !$class['extension'] || !$class['calls'] || !$this->isSpecialPage( $key ) || $this->hasIntermediateConstructor( $key ) ) {
				continue;
			}

			$overridden = $this->overridesRestriction( $key );
			foreach ( $class['calls'] as $call ) {
				$this->classify( $call, $overridden, $class['file'], $class['name'], $results );
			}
		}

		foreach ( $this->instantiations as $instance ) {
			$key = $this->lookupClass( $instance['class'] );
			if ( $key === null || !$this->isSpecialPage( $key ) ) {
				continue;
			}

			$root = $this->isRoot( $key );
			if ( !$root && ( $this->classes[$key]['constructor'] || $this->hasIntermediateConstructor( $key ) ) ) {
				continue;
			}

			$this->classify( $instance['call'], $this->overridesRestriction( $key ), $instance['file'], $instance['name'], $results );
		}

		usort(
			$results,
			static fn ( array $a, array $b ): int => strcmp( $a[0], $b[0] ) ?: $a[1] <=> $b[1]
		);

		foreach ( $results as [ $file, $line, $name, $text ] ) {
			$this->output( $file . ':' . $line . ' ' . $name . ' ' . $text . "\n" );
		}

		$this->output( "\nCount: " . count( $results ) . "\n" );
	}

	private function classify( array $call, bool $overridden, string $file, string $name, array &$results ): void {
		if (
			$call['arguments'] < 2 ||
			$overridden ||
			in_array( strtolower( $call['restriction'] ), self::EMPTY_VALUES, true )
		) {
			return;
		}

		$results[] = [ $file, $call['line'], $name, $call['text'] ];
	}

	private function scanDirectory( string $directory, string $base, bool $extension ): void {
		foreach ( $this->iterateFiles( $directory, self::SKIPPED_DIRECTORIES ) as $file ) {
			if ( !str_ends_with( $file, '.php' ) ) {
				continue;
			}

			$code = file_get_contents( $file );
			if ( $code !== false && ( str_contains( $code, 'class ' ) || str_contains( $code, 'new ' ) ) ) {
				$this->parseFile( $code, substr( $file, strlen( $base ) + 1 ), $extension );
			}
		}
	}

	private function lookupClass( string $name ): ?string {
		$key = strtolower( ltrim( $name, '\\' ) );
		if ( isset( $this->classes[$key] ) ) {
			return $key;
		}

		foreach ( $this->shortNames[$this->shortName( $name )] ?? [] as $candidate ) {
			if ( !$this->classes[$candidate]['extension'] ) {
				return $candidate;
			}
		}

		return ( $this->shortNames[$this->shortName( $name )] ?? [ null ] )[0];
	}

	private function isRoot( string $key ): bool {
		return $this->classes[$key]['short'] === self::ROOT_CLASS && !$this->classes[$key]['extension'];
	}

	/**
	 * @return array{0: string[], 1: bool}
	 */
	private function chain( string $key ): array {
		$chain = [];
		$seen = [ $key => true ];
		$parent = $this->classes[$key]['parent'];
		while ( $parent !== '' ) {
			$found = $this->lookupClass( $parent );
			if ( $found === null ) {
				return [ $chain, $this->shortName( $parent ) === self::ROOT_CLASS ];
			}

			if ( isset( $seen[$found] ) ) {
				break;
			}

			if ( $this->isRoot( $found ) ) {
				return [ $chain, true ];
			}

			$seen[$found] = true;
			$chain[] = $found;
			$parent = $this->classes[$found]['parent'];
		}

		return [ $chain, false ];
	}

	private function isSpecialPage( string $key ): bool {
		return $this->isRoot( $key ) || $this->chain( $key )[1];
	}

	private function hasIntermediateConstructor( string $key ): bool {
		foreach ( $this->chain( $key )[0] as $parent ) {
			if ( $this->classes[$parent]['constructor'] ) {
				return true;
			}
		}

		return false;
	}

	private function overridesRestriction( string $key ): bool {
		if ( $this->classes[$key]['override'] && !$this->isRoot( $key ) ) {
			return true;
		}

		foreach ( $this->chain( $key )[0] as $parent ) {
			if ( $this->classes[$parent]['override'] ) {
				return true;
			}
		}

		return false;
	}

	private function shortName( string $name ): string {
		$position = strrpos( $name, '\\' );
		return strtolower( $position === false ? $name : substr( $name, $position + 1 ) );
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

	private function parseImport( array $tokens, int $i, array &$imports ): int {
		$count = count( $tokens );
		$j = $i + 1;
		if ( $tokens[$j]->is( [ T_FUNCTION, T_CONST ] ) ) {
			while ( $j < $count && $tokens[$j]->text !== ';' ) {
				$j++;
			}

			return $j;
		}

		$prefix = '';
		for ( ; $j < $count && $tokens[$j]->text !== ';'; $j++ ) {
			$token = $tokens[$j];
			if ( !$token->is( [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ] ) ) {
				continue;
			}

			$name = ltrim( $token->text, '\\' );
			if ( isset( $tokens[$j + 1] ) && $tokens[$j + 1]->is( T_NS_SEPARATOR ) ) {
				$prefix = $name . '\\';
				$j += 2;
				continue;
			}

			$full = $prefix . $name;
			$alias = substr( strrchr( '\\' . $full, '\\' ), 1 );
			if ( isset( $tokens[$j + 1] ) && $tokens[$j + 1]->is( T_AS ) && isset( $tokens[$j + 2] ) ) {
				$alias = $tokens[$j + 2]->text;
				$j += 2;
			}

			$imports[strtolower( $alias )] = $full;
		}

		return $j;
	}

	private function parseFile( string $code, string $file, bool $extension ): void {
		$tokens = array_values( array_filter(
			PhpToken::tokenize( $code ),
			static fn ( PhpToken $token ): bool => !$token->is( [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ] )
		) );

		$count = count( $tokens );
		$namespace = '';
		$imports = [];
		$depth = 0;
		$stack = [];
		$pending = null;

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[$i];
			$prev = $tokens[$i - 1] ?? null;
			$next = $tokens[$i + 1] ?? null;

			if ( $token->text === '{' || $token->is( [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ] ) ) {
				$depth++;
				if ( $pending !== null ) {
					$stack[] = [ 'key' => $pending, 'depth' => $depth ];
					$pending = null;
				}

				continue;
			}

			if ( $token->text === '}' ) {
				if ( $stack && end( $stack )['depth'] === $depth ) {
					array_pop( $stack );
				}

				$depth--;
				continue;
			}

			if ( $token->is( T_NAMESPACE ) && $next && $next->is( [ T_STRING, T_NAME_QUALIFIED ] ) ) {
				$namespace = $next->text;
				$imports = [];
				$i++;
				continue;
			}

			if ( $token->is( T_USE ) && !$stack && $next && $next->text !== '(' ) {
				$i = $this->parseImport( $tokens, $i, $imports );
				continue;
			}

			if ( $token->is( T_CLASS ) && $next && $next->is( T_STRING ) && !( $prev && $prev->is( [ T_DOUBLE_COLON, T_NEW ] ) ) ) {
				$name = ( $namespace !== '' ? $namespace . '\\' : '' ) . $next->text;
				$key = strtolower( $name );
				$parent = '';
				for ( $j = $i + 2; $j < $count && $tokens[$j]->text !== '{'; $j++ ) {
					if ( $tokens[$j]->is( T_EXTENDS ) && isset( $tokens[$j + 1] ) ) {
						$parent = $this->resolveClass( $tokens[$j + 1]->text, $tokens[$j + 1]->id, $namespace, $imports );
						break;
					}
				}

				$this->classes[$key] = [
					'short' => strtolower( $next->text ),
					'name' => $name,
					'parent' => $parent,
					'override' => false,
					'constructor' => false,
					'calls' => [],
					'file' => $file,
					'extension' => $extension,
				];
				$this->shortNames[strtolower( $next->text )][] = $key;

				$pending = $key;
				$i++;
				continue;
			}

			if (
				$extension &&
				$token->is( T_NEW ) &&
				$next && $next->is( self::NAME_TOKENS ) &&
				isset( $tokens[$i + 2] ) && $tokens[$i + 2]->text === '('
			) {
				$call = $this->parseCall( $tokens, $i + 2, $token->line, 'new ' . $next->text );
				if ( $call !== null && $call['arguments'] >= 2 ) {
					$this->instantiations[] = [
						'class' => $this->resolveClass( $next->text, $next->id, $namespace, $imports ),
						'name' => $next->text,
						'file' => $file,
						'call' => $call,
					];
				}

				continue;
			}

			if ( !$stack ) {
				continue;
			}

			$current = end( $stack )['key'];
			if ( $token->is( T_FUNCTION ) && $next && $next->is( T_STRING ) ) {
				$method = strtolower( $next->text );
				if ( $method === 'getrestriction' ) {
					$this->classes[$current]['override'] = true;
				} elseif ( $method === '__construct' ) {
					$this->classes[$current]['constructor'] = true;
				}

				continue;
			}

			if (
				$token->is( self::NAME_TOKENS ) &&
				!in_array( strtolower( $token->text ), [ 'self', 'static' ], true ) &&
				$next && $next->is( T_DOUBLE_COLON ) &&
				isset( $tokens[$i + 3] ) &&
				strtolower( $tokens[$i + 2]->text ) === '__construct' &&
				$tokens[$i + 3]->text === '('
			) {
				$call = $this->parseCall( $tokens, $i + 3, $token->line, $token->text . '::__construct' );
				if ( $call !== null && $this->classes[$current]['file'] === $file ) {
					$this->classes[$current]['calls'][] = $call;
				}
			}
		}
	}

	private function parseCall( array $tokens, int $open, int $line, string $prefix ): ?array {
		$arguments = [];
		$buffer = '';
		$depth = 0;
		$count = count( $tokens );
		for ( $j = $open; $j < $count; $j++ ) {
			$text = $tokens[$j]->text;
			if ( $text === '(' || $text === '[' ) {
				$depth++;
				if ( $depth === 1 ) {
					continue;
				}
			} elseif ( $text === ')' || $text === ']' ) {
				$depth--;
				if ( $depth === 0 ) {
					if ( $buffer !== '' ) {
						$arguments[] = $buffer;
					}

					break;
				}
			} elseif ( $text === ',' && $depth === 1 ) {
				$arguments[] = $buffer;
				$buffer = '';
				continue;
			}

			$buffer .= $buffer === '' ? $text : ' ' . $text;
		}

		if ( !$arguments ) {
			return null;
		}

		return [
			'line' => $line,
			'arguments' => count( $arguments ),
			'restriction' => $arguments[1] ?? '',
			'text' => $prefix . '( ' . implode( ', ', $arguments ) . ' )',
		];
	}
}

// @codeCoverageIgnoreStart
return FindMissingGetRestriction::class;
// @codeCoverageIgnoreEnd
