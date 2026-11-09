<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List new or removed virtual database domains between two MediaWiki versions.
 *
 * Since 1.47 domains no longer need to be declared in extension.json, so PHP files are
 * scanned for virtual-* names that are used with database connection code.
 *
 * Usage:
 *     php run.php MirahezeMagic:FindNewVirtualDomains --from-version=current_version --to-version=new_version
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

use MediaWiki\MainConfigNames;
use Miraheze\MirahezeMagic\Maintenance\Base\VersionComparisonMaintenance;
use function array_diff_key;
use function array_keys;
use function array_unique;
use function count;
use function file_get_contents;
use function implode;
use function is_string;
use function ksort;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_ends_with;

class FindNewVirtualDomains extends VersionComparisonMaintenance {

	private const string DB_USAGE = '/ConnectionProvider|getPrimaryDatabase|getReplicaDatabase|VirtualDomain|VIRTUAL_|LoadBalancer/';

	private const array SKIPPED_DIRECTORIES = [ 'tests', 'node_modules', '.git', 'i18n', 'docs' ];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new or removed virtual database domains between two MediaWiki versions.' );

		$this->addEnabledOnlyOption();
	}

	public function execute() {
		$from = $this->findDomains( $this->getFromVersion() );
		$to = $this->findDomains( $this->getToVersion() );
		$mapping = $this->getConfig()->get( MainConfigNames::VirtualDomainsMapping );

		$new = array_diff_key( $to, $from );
		$removed = array_diff_key( $from, $to );

		foreach ( $new as $domain => $info ) {
			$status = isset( $mapping[$domain] ) ? 'mapped' : 'unmapped';
			$this->output( 'NEW ' . $domain . ' ' . $this->describe( $info ) . ' ' . $status . "\n" );
		}

		foreach ( $removed as $domain => $info ) {
			$status = isset( $mapping[$domain] ) ? 'still mapped' : 'not mapped';
			$this->output( 'REMOVED ' . $domain . ' ' . $this->describe( $info ) . ' ' . $status . "\n" );
		}

		$this->output( "\nNew: " . count( $new ) . ', removed: ' . count( $removed ) . "\n" );
	}

	private function describe( array $info ): string {
		$text = '[' . implode( ', ', array_keys( $info['sources'] ) ) . ']';
		return $info['declared'] ? $text : $text . ' (not in extension.json)';
	}

	private function findDomains( string $version ): array {
		$base = $this->getVersionPath( $version );
		$domains = [];
		$roots = [ 'core' => $base . '/includes' ];

		foreach ( $this->findManifestFiles( $version ) as $entry ) {
			$roots[$entry['dir']] = $entry['path'];
			foreach ( $entry['info']['DatabaseVirtualDomains'] ?? [] as $key => $value ) {
				$domain = is_string( $value ) ? $value : $key;
				if ( is_string( $domain ) ) {
					$this->addDomain( $domains, $domain, $entry['dir'], true );
				}
			}
		}

		foreach ( $roots as $name => $root ) {
			foreach ( $this->iterateFiles( $root, self::SKIPPED_DIRECTORIES ) as $file ) {
				if ( !str_ends_with( $file, '.php' ) ) {
					continue;
				}

				$contents = file_get_contents( $file );
				if ( $contents === false || !str_contains( $contents, 'virtual-' ) ) {
					continue;
				}

				$matches = [];
				if ( !preg_match_all( '/[\'"](virtual-[A-Za-z0-9_-]+)[\'"]/', $contents, $matches ) ) {
					continue;
				}

				if ( !preg_match( self::DB_USAGE, $contents ) ) {
					continue;
				}

				foreach ( array_unique( $matches[1] ) as $domain ) {
					$this->addDomain( $domains, $domain, $name, false );
				}
			}
		}

		ksort( $domains );
		return $domains;
	}

	private function addDomain( array &$domains, string $domain, string $source, bool $declared ): void {
		$domains[$domain]['sources'][$source] = true;
		$domains[$domain]['declared'] = ( $domains[$domain]['declared'] ?? false ) || $declared;
	}
}

// @codeCoverageIgnoreStart
return FindNewVirtualDomains::class;
// @codeCoverageIgnoreEnd
