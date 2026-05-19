<?php

namespace Miraheze\MirahezeMagic\HookHandlers;

use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Hook\SkinEditSectionLinksHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;

class NoFollow implements
	SidebarBeforeOutputHook,
	SkinTemplateNavigation__UniversalHook,
	SkinEditSectionLinksHook
{

	private string $specialPrefix;

	public static function registerHooks(): void {
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		$handler = new self();
		$hookContainer->register(
			'SidebarBeforeOutput',
			[
				$handler,
				'onSidebarBeforeOutput'
			]
		);
		$hookContainer->register(
			'SkinTemplateNavigation::Universal',
			[
				$handler,
				'onSkinTemplateNavigation__Universal'
			]
		);
		$hookContainer->register(
			'SkinEditSectionLinks',
			[
				$handler,
				'onSkinEditSectionLinks'
			]
		);
	}

	public function __construct() {
		$specialPagePath = SpecialPage::getTitleFor( 'Badtitle' )->getLocalURL();
		$this->specialPrefix = substr( $specialPagePath, 0, strpos( $specialPagePath, 'Badtitle' ) );
	}

	private function needsNoFollow( string $href ): bool {
		return str_starts_with( $href, $this->specialPrefix ) ||
			preg_match( '/[&?](action|veaction|diff|curid|oldid)=/i', $href );
	}

	private function addNoFollow( array &$attrs ): void {
		$existing = $attrs['rel'] ?? '';
		if ( !str_contains( $existing, 'nofollow' ) ) {
			$attrs['rel'] = $existing !== '' ? $existing . ' nofollow' : 'nofollow';
		}
	}

	private function checkArrayForLinks( array &$items ): void {
		foreach ( $items as &$item ) {
			if ( $this->needsNoFollow( $item['href'] ?? '' ) ) {
				$this->addNoFollow( $item );
			}
		}
	}

	/**
	 * @inheritDoc
	 * @param $skin @phan-unused-param
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		// Check sidebar links (e.g. main menu, tools)
		foreach ( $sidebar as &$items ) {
			$this->checkArrayForLinks( $items );
		}
	}

	/**
	 * @inheritDoc
	 * @param $sktemplate @phan-unused-param
	 */
	public function onSkinTemplateNavigation__Universal( $sktemplate, &$links ): void {
		// Edit/history/other views. Does not work for VE yet (depends on order of hooks?).
		$this->checkArrayForLinks( $links['views'] );
		// Purge/delete/protect. Only purge matters because anons can't do the rest.
		$this->checkArrayForLinks( $links['actions'] );
		// Check for Special:UserLogin and Special:CreateAccount
		// This part does not work yet because MW core drops the rel key when building the HTML output.
		$this->checkArrayForLinks( $links['user-menu'] );
	}

	/**
	 * @inheritDoc
	 * @param $skin @phan-unused-param
	 * @param $title @phan-unused-param
	 * @param $section @phan-unused-param
	 * @param $sectionTitle @phan-unused-param
	 * @param $lang @phan-unused-param
	 */
	public function onSkinEditSectionLinks(
		$skin,
		$title,
		$section,
		$sectionTitle,
		&$result,
		$lang
	) {
		// Add nofollow to section editing links.
		foreach ( $result as &$link ) {
			if ( is_array( $link ) && array_key_exists( 'attribs', $link ) ) {
				$this->addNoFollow( $link['attribs'] );
			}
		}
	}
}
