<?php
/**
 * Admin menu: "Content Publisher" with Posts, Add posts, Client sites, Pipeline test, Settings, Status.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Menu {

	public const SLUG = 'cpub-publisher';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_pages' ) );
		add_action( 'admin_menu', array( self::class, 'arrange' ), 99 );
		add_filter( 'parent_file', array( self::class, 'parent_file' ) );
		add_filter( 'submenu_file', array( self::class, 'submenu_file' ) );
	}

	/** Keep our menu open and the right entry highlighted on the pages without a parent. */
	private const HIGHLIGHT = array( EditorPage::SLUG => PostsPage::SLUG, SiteSettingsPage::SLUG => self::SLUG );

	private static function current(): string {
		return sanitize_key( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function parent_file( $file ) {
		return isset( self::HIGHLIGHT[ self::current() ] ) ? self::SLUG : $file;
	}

	public static function submenu_file( $file ) {
		return self::HIGHLIGHT[ self::current() ] ?? $file;
	}

	/** Posts first: the top-level item opens the first entry. */
	public static function arrange(): void {
		global $submenu;
		if ( empty( $submenu[ self::SLUG ] ) ) {
			return;
		}
		$items = array_values( $submenu[ self::SLUG ] );
		$order  = array( PostsPage::SLUG, AddPostsPage::SLUG, self::SLUG );
		usort(
			$items,
			function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a[2], $order, true );
				$ib = array_search( $b[2], $order, true );
				return ( false === $ia ? 99 : $ia ) <=> ( false === $ib ? 99 : $ib );
			}
		);
		$submenu[ self::SLUG ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	public function add_pages(): void {
		$hook = add_menu_page(
			'Content Publisher',
			'Content Publisher',
			Capabilities::REVIEW,
			self::SLUG,
			array( new SitesPage(), 'render' ),
			'dashicons-upload',
			26
		);
		add_action( 'load-' . $hook, array( SitesPage::class, 'load' ) );
		// Same slug as the top-level page: renames its first submenu item. No callback,
		// or WordPress would render the page twice.
		add_submenu_page( self::SLUG, 'Client sites', 'Client sites', Capabilities::REVIEW, self::SLUG, '' );
		add_submenu_page( self::SLUG, 'Posts', 'Posts', Capabilities::REVIEW, PostsPage::SLUG, array( new PostsPage(), 'render' ) );
		add_submenu_page( self::SLUG, 'Add posts', 'Add posts', Capabilities::REVIEW, AddPostsPage::SLUG, array( new AddPostsPage(), 'render' ) );
		// Pages reached from other pages: registered without a parent, so they are
		// not in the menu (a page merely removed from the menu is refused by WordPress).
		$editor = add_submenu_page( '', 'Review post', 'Review post', Capabilities::REVIEW, EditorPage::SLUG, array( new EditorPage(), 'render' ) );
		add_action( 'load-' . $editor, array( EditorPage::class, 'enqueue' ) );
		$site = add_submenu_page( '', 'Client site settings', 'Client site settings', Capabilities::MANAGE, SiteSettingsPage::SLUG, array( new SiteSettingsPage(), 'render' ) );
		// Pages without a parent have no title for the browser tab otherwise.
		foreach ( array( $editor => 'Review post', $site => 'Client site settings' ) as $hook => $page_title ) {
			add_action(
				'load-' . $hook,
				static function () use ( $page_title ) {
					$GLOBALS['title'] = $page_title; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				}
			);
		}
		add_submenu_page( self::SLUG, 'Pipeline test', 'Pipeline test', Capabilities::MANAGE, PipelinePage::SLUG, array( new PipelinePage(), 'render' ) );
		add_submenu_page( self::SLUG, 'Settings', 'Settings', Capabilities::MANAGE, SettingsPage::SLUG, array( new SettingsPage(), 'render' ) );
		add_submenu_page( self::SLUG, 'Status', 'Status', Capabilities::MANAGE, self::SLUG . '-status', array( new StatusPage(), 'render' ) );
	}
}
