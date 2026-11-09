<?php

namespace Miraheze\MirahezeMagic\Maintenance;

/**
 * List new, removed or changed rights, groups, grants and namespaces between two MediaWiki versions.
 *
 * Usage:
 *     php run.php MirahezeMagic:FindAuthorizationChanges --from-version=current_version --to-version=new_version
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

class FindAuthorizationChanges extends VersionComparisonMaintenance {

	private const array GROUP_LISTS = [
		MainConfigNames::AddGroups => 'addgroups',
		MainConfigNames::RemoveGroups => 'removegroups',
		MainConfigNames::GroupsAddToSelf => 'groupsaddtoself',
		MainConfigNames::GroupsRemoveFromSelf => 'groupsremovefromself',
	];

	private const array NAMESPACE_MAPS = [
		MainConfigNames::ExtraNamespaces => 'name',
		MainConfigNames::NamespaceProtection => 'protection',
		MainConfigNames::NamespacesWithSubpages => 'subpages',
		MainConfigNames::NamespaceContentModels => 'defaultcontentmodel',
		MainConfigNames::CapitalLinkOverrides => 'capitalized',
	];

	private const array NAMESPACE_LISTS = [
		MainConfigNames::ContentNamespaces => 'content',
		MainConfigNames::NonincludableNamespaces => 'nonincludable',
	];

	private const array NAMESPACE_PROPERTIES = [
		'constant', 'name', 'subpages', 'content', 'defaultcontentmodel',
		'protection', 'capitalized', 'conditional',
	];

	public function __construct() {
		parent::__construct();

		$this->addDescription( 'List new, removed or changed rights, groups, grants and namespaces between two MediaWiki versions.' );

		$this->addEnabledOnlyOption();
		$this->addOption( 'filter', 'Only show entries whose key contains this text', false, true );
	}

	public function execute() {
		$from = $this->collect( $this->getFromVersion() );
		$to = $this->collect( $this->getToVersion() );
		$filter = $this->getOption( 'filter' );

		$new = [];
		$removed = [];
		$changed = [];

		foreach ( $to as $key => $entry ) {
			if ( $filter !== null && !str_contains( $key, $filter ) ) {
				continue;
			}

			if ( !isset( $from[$key] ) ) {
				$new[$key] = $entry;
			} elseif ( $from[$key]['value'] !== $entry['value'] ) {
				$changed[$key] = [ $from[$key], $entry ];
			}
		}

		foreach ( $from as $key => $entry ) {
			if ( !isset( $to[$key] ) && ( $filter === null || str_contains( $key, $filter ) ) ) {
				$removed[$key] = $entry;
			}
		}

		foreach ( $new as $key => $entry ) {
			$this->output( 'NEW ' . $key . ' [' . $this->sources( $entry ) . '] ' . $this->short( $entry['value'] ) . "\n" );
		}

		foreach ( $removed as $key => $entry ) {
			$this->output( 'REMOVED ' . $key . ' [' . $this->sources( $entry ) . '] ' . $this->short( $entry['value'] ) . "\n" );
		}

		foreach ( $changed as $key => [ $old, $current ] ) {
			$this->output(
				'CHANGED ' . $key . ' [' . $this->sources( $current ) . '] ' .
				$this->short( $old['value'] ) . ' => ' . $this->short( $current['value'] ) . "\n"
			);
		}

		$this->output(
			"\nNew: " . count( $new ) . ', removed: ' . count( $removed ) . ', changed: ' . count( $changed ) . "\n"
		);
	}

	private function sources( array $entry ): string {
		return implode( ', ', array_keys( $entry['sources'] ) );
	}

	private function collect( string $version ): array {
		$entries = [];
		$schema = $this->loadCoreSchema( $version );
		$this->readAttributes( $schema['config-schema-inverse']['default'] ?? [], 'core', $entries );

		$defines = file_get_contents( $this->getVersionPath( $version ) . '/includes/Defines.php' ) ?: '';
		if ( preg_match_all( '/^define\(\s*\'(NS_[A-Z0-9_]+)\'\s*,\s*(-?\d+)\s*\)/m', $defines, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$this->add( $entries, 'namespace-constant:' . $match[1], (int)$match[2], 'core' );
			}
		}

		foreach ( $this->findManifests( $version ) as $name => $info ) {
			$this->readAttributes( $info, $name, $entries );
		}

		ksort( $entries );
		return $entries;
	}

	private function readAttributes( array $attrs, string $source, array &$entries ): void {
		foreach ( $attrs[MainConfigNames::AvailableRights] ?? [] as $right ) {
			$this->add( $entries, 'right:' . $right, true, $source );
		}

		foreach ( $attrs[MainConfigNames::ImplicitGroups] ?? [] as $group ) {
			$this->add( $entries, 'group:' . $group, true, $source );
			$this->add( $entries, 'implicitgroup:' . $group, true, $source );
		}

		foreach ( [ MainConfigNames::GroupPermissions => 'permission', MainConfigNames::RevokePermissions => 'revoke' ] as $attr => $prefix ) {
			foreach ( $attrs[$attr] ?? [] as $group => $rights ) {
				$this->add( $entries, 'group:' . $group, true, $source );
				foreach ( (array)$rights as $right => $allowed ) {
					$this->add( $entries, $prefix . ':' . $group . ':' . $right, (bool)$allowed, $source );
				}
			}
		}

		foreach ( self::GROUP_LISTS as $attr => $prefix ) {
			foreach ( $attrs[$attr] ?? [] as $group => $targets ) {
				$this->add( $entries, 'group:' . $group, true, $source );
				$this->add( $entries, $prefix . ':' . $group, $targets, $source );
			}
		}

		foreach ( $attrs[MainConfigNames::GrantPermissions] ?? [] as $grant => $rights ) {
			foreach ( (array)$rights as $right => $allowed ) {
				$this->add( $entries, 'grant:' . $grant . ':' . $right, (bool)$allowed, $source );
			}
		}

		foreach ( [ MainConfigNames::GrantPermissionGroups => 'grantgroup', MainConfigNames::GrantRiskGroups => 'grantrisk' ] as $attr => $prefix ) {
			foreach ( $attrs[$attr] ?? [] as $grant => $value ) {
				$this->add( $entries, $prefix . ':' . $grant, $value, $source );
			}
		}

		foreach ( self::NAMESPACE_MAPS as $attr => $property ) {
			foreach ( $attrs[$attr] ?? [] as $id => $value ) {
				$this->add( $entries, 'namespace:' . $id . ':' . $property, $value, $source );
			}
		}

		foreach ( self::NAMESPACE_LISTS as $attr => $property ) {
			foreach ( $attrs[$attr] ?? [] as $id ) {
				$this->add( $entries, 'namespace:' . $id . ':' . $property, true, $source );
			}
		}

		foreach ( $attrs['namespaces'] ?? [] as $namespace ) {
			$id = $namespace['id'] ?? $namespace['constant'] ?? null;
			if ( $id === null ) {
				continue;
			}

			foreach ( self::NAMESPACE_PROPERTIES as $property ) {
				if ( array_key_exists( $property, $namespace ) ) {
					$this->add( $entries, 'namespace:' . $id . ':' . $property, $namespace[$property], $source );
				}
			}
		}
	}

	private function add( array &$entries, string $key, mixed $value, string $source ): void {
		$value = $this->normalize( $value );
		if ( isset( $entries[$key] ) ) {
			$old = $entries[$key]['value'];
			if ( is_array( $old ) && is_array( $value ) && array_is_list( $old ) && array_is_list( $value ) ) {
				$value = array_values( array_unique( array_merge( $old, $value ) ) );
				sort( $value );
			}
		}

		$entries[$key]['value'] = $value;
		$entries[$key]['sources'][$source] = true;
	}
}

// @codeCoverageIgnoreStart
return FindAuthorizationChanges::class;
// @codeCoverageIgnoreEnd
