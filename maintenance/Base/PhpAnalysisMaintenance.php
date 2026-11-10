<?php

namespace Miraheze\MirahezeMagic\Maintenance\Base;

/**
 * Shared helpers for version comparison maintenance scripts that analyze PHP source code.
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

use PhpToken;
use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function ltrim;
use function min;
use function str_replace;
use function strlen;
use function strrchr;
use function strstr;
use function strtolower;
use function substr;
use function trim;
use const PHP_INT_MAX;
use const T_AS;
use const T_ATTRIBUTE;
use const T_BOOLEAN_AND;
use const T_BOOLEAN_OR;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ELSE;
use const T_ELSEIF;
use const T_FUNCTION;
use const T_IF;
use const T_LNUMBER;
use const T_LOGICAL_AND;
use const T_LOGICAL_OR;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NS_SEPARATOR;
use const T_STRING;
use const T_WHITESPACE;

abstract class PhpAnalysisMaintenance extends VersionComparisonMaintenance {

	protected const array NAME_TOKENS = [ T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE ];

	protected function tokenize( string $code ): array {
		return array_values( array_filter(
			PhpToken::tokenize( $code ),
			static fn ( PhpToken $token ): bool => !$token->is( [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ] )
		) );
	}

	protected function tokenizeWithDocs( string $code ): array {
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

	protected function skipAttribute( array $tokens, int $i ): int {
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

	protected function stringsInCall( array $tokens, int $open ): array {
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

	protected function matchBackward( array $tokens, int $close ): int {
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

	protected function resolveClass( string $name, int $type, string $namespace, array $imports ): string {
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

	protected function parseImport(
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

	/**
	 * @return array<int, PhpToken[]>
	 */
	protected function splitArguments( array $tokens, int $open ): array {
		$arguments = [];
		$current = [];
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
					break;
				}
			} elseif ( $text === ',' && $depth === 1 ) {
				$arguments[] = $current;
				$current = [];
				continue;
			}

			$current[] = $tokens[$j];
		}

		if ( $current ) {
			$arguments[] = $current;
		}

		return $arguments;
	}

	/**
	 * @param array<int, array{from: int, to: int, bound: int, check: ?string}> $guards
	 */
	protected function findGuardCheck( array $guards, int $position, int $targetId ): ?string {
		$labels = [];
		foreach ( $guards as $guard ) {
			if ( $position < $guard['from'] || $position > $guard['to'] ) {
				continue;
			}

			if ( $guard['bound'] <= $targetId ) {
				$labels['version check'] = true;
			}

			if ( $guard['check'] !== null ) {
				$labels[$guard['check']] = true;
			}
		}

		return $labels ? implode( ', ', array_keys( $labels ) ) : null;
	}

	/**
	 * @param string[] $versionNames Constants or variables used with version_compare()
	 * @param string[] $idNames Constants holding an integer version compared directly
	 * @return array<int, array{from: int, to: int, bound: int, check: ?string}>
	 */
	protected function findGuards( array $tokens, array $versionNames, array $idNames ): array {
		$guards = [];
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( $tokens[$i]->id !== T_IF ) {
				continue;
			}

			$elseBound = PHP_INT_MAX;
			$chainCheck = null;
			$j = $i;
			while ( true ) {
				$open = $j + 1;
				if ( ( $tokens[$open]->text ?? '' ) !== '(' ) {
					break;
				}

				$close = $open;
				$depth = 0;
				for ( ; $close < $count; $close++ ) {
					if ( $tokens[$close]->text === '(' ) {
						$depth++;
					} elseif ( $tokens[$close]->text === ')' && --$depth === 0 ) {
						break;
					}
				}

				if ( $close >= $count - 1 || $tokens[$close + 1]->text === ':' ) {
					break;
				}

				$guard = $this->parseGuard( array_slice( $tokens, $open + 1, $close - $open - 1 ), $versionNames, $idNames );
				$end = $this->skipBody( $tokens, $close + 1 );
				$bound = min( $elseBound, $guard['then'] );
				$check = $chainCheck ?? $guard['check'];
				if ( $bound < PHP_INT_MAX || $check !== null ) {
					$guards[] = [
						'from' => $tokens[$close + 1]->pos,
						'to' => $tokens[$end]->pos,
						'bound' => $bound,
						'check' => $check,
					];
				}

				$elseBound = min( $elseBound, $guard['else'] );
				$chainCheck ??= $guard['check'];
				$next = $end + 1;
				if ( isset( $tokens[$next] ) && $tokens[$next]->id === T_ELSEIF ) {
					$j = $next;
					continue;
				}

				if ( isset( $tokens[$next] ) && $tokens[$next]->id === T_ELSE ) {
					if ( isset( $tokens[$next + 1] ) && $tokens[$next + 1]->id === T_IF ) {
						$j = $next + 1;
						continue;
					}

					if ( ( $elseBound < PHP_INT_MAX || $chainCheck !== null ) && isset( $tokens[$next + 1] ) ) {
						$elseEnd = $this->skipBody( $tokens, $next + 1 );
						$guards[] = [
							'from' => $tokens[$next + 1]->pos,
							'to' => $tokens[$elseEnd]->pos,
							'bound' => $elseBound,
							'check' => $chainCheck,
						];
					}
				}

				break;
			}
		}

		return $guards;
	}

	/**
	 * @return array{then: int, else: int, check: ?string}
	 */
	private function parseGuard( array $condition, array $versionNames, array $idNames ): array {
		$none = [ 'then' => PHP_INT_MAX, 'else' => PHP_INT_MAX, 'check' => null ];
		$operators = [ '<' => '<', 'lt' => '<', '<=' => '<=', 'le' => '<=', '>' => '>', 'gt' => '>', '>=' => '>=', 'ge' => '>=' ];
		$checks = [
			'function_exists',
			'class_exists',
			'interface_exists',
			'trait_exists',
			'enum_exists',
			'defined',
			'method_exists',
			'property_exists',
			'is_callable',
		];
		$hasAnd = false;
		$terms = [];
		$check = null;
		$total = count( $condition );

		for ( $k = 0; $k < $total; $k++ ) {
			$token = $condition[$k];
			$name = strtolower( ltrim( $token->text, '\\' ) );
			$next = $condition[$k + 1] ?? null;
			if ( $token->id === T_BOOLEAN_OR || $token->id === T_LOGICAL_OR ) {
				return $none;
			}

			if ( $token->id === T_BOOLEAN_AND || $token->id === T_LOGICAL_AND ) {
				$hasAnd = true;
			} elseif ( in_array( $name, $idNames, true ) && $next !== null && isset( $operators[$next->text] ) && ( $condition[$k + 2]->id ?? null ) === T_LNUMBER ) {
				$terms[] = [ $operators[$next->text], (int)$condition[$k + 2]->text ];
			} elseif ( $name === 'version_compare' && $next !== null && $next->text === '(' ) {
				if ( $k > 0 && $condition[$k - 1]->text === '!' ) {
					return $none;
				}

				$arguments = $this->splitArguments( $condition, $k + 1 );
				if ( count( $arguments ) !== 3 || count( $arguments[0] ) !== 1 || count( $arguments[1] ) !== 1 || count( $arguments[2] ) !== 1 ) {
					continue;
				}

				$operator = $operators[strtolower( trim( $arguments[2][0]->text, '\'"' ) )] ?? null;
				if ( in_array( strtolower( ltrim( $arguments[0][0]->text, '\\' ) ), $versionNames, true ) && $operator !== null ) {
					$terms[] = [ $operator, $this->versionId( trim( $arguments[1][0]->text, '\'"' ) ) ];
				}
			} elseif ( in_array( $name, $checks, true ) && $next !== null && $next->text === '(' ) {
				$check ??= $name;
			}
		}

		$result = [ 'then' => PHP_INT_MAX, 'else' => PHP_INT_MAX, 'check' => $check ];
		foreach ( $terms as [ $operator, $id ] ) {
			if ( $operator === '<' ) {
				$result['then'] = min( $result['then'], $id );
			} elseif ( $operator === '<=' ) {
				$result['then'] = min( $result['then'], $id + 1 );
			}
		}

		if ( !$hasAnd && count( $terms ) === 1 ) {
			[ $operator, $id ] = $terms[0];
			if ( $operator === '>=' ) {
				$result['else'] = $id;
			} elseif ( $operator === '>' ) {
				$result['else'] = $id + 1;
			}
		}

		return $result;
	}

	protected function versionId( string $version ): int {
		$parts = explode( '.', $version );
		return (int)$parts[0] * 10000 + (int)( $parts[1] ?? 0 ) * 100 + (int)( $parts[2] ?? 0 );
	}

	protected function skipBody( array $tokens, int $from ): int {
		$count = count( $tokens );
		$j = $from;
		while ( $j < $count && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';' ) {
			$j++;
		}

		if ( $j >= $count || $tokens[$j]->text === ';' ) {
			return $j;
		}

		$depth = 0;
		for ( ; $j < $count; $j++ ) {
			$token = $tokens[$j];
			if ( $token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES ) {
				$depth++;
			} elseif ( $token->text === '}' ) {
				$depth--;
				if ( $depth === 0 ) {
					return $j;
				}
			}
		}

		return $j;
	}
}
