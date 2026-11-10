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
use function array_values;
use function count;
use function ltrim;
use function str_replace;
use function strlen;
use function strrchr;
use function strstr;
use function strtolower;
use function substr;
use const T_AS;
use const T_ATTRIBUTE;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOC_COMMENT;
use const T_FUNCTION;
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
}
