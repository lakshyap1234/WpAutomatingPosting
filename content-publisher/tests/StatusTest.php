<?php

use CPub\Publisher\Admin\StatusPage;
use CPub\Publisher\Capabilities;
use CPub\Publisher\Installer;
use CPub\Publisher\Status\Checks;

class StatusTest extends WP_UnitTestCase {

	private function statuses(): array {
		return array_column( Checks::run(), 'status', 'id' );
	}

	public function test_all_checks_run(): void {
		$this->assertSame(
			array( 'encryption_key', 'tables', 'libraries', 'action_scheduler', 'cron', 'https', 'wpcom_app', 'unicode', 'ai' ),
			array_keys( $this->statuses() )
		);
	}

	public function test_unicode_and_ai_checks(): void {
		$this->assertSame( Checks::OK, Checks::unicode( true )['status'] );
		$this->assertSame( Checks::WARN, Checks::unicode( false )['status'] );
		$this->assertSame( Checks::WARN, $this->statuses()['ai'] );
		\CPub\Publisher\Settings\AiSettings::save( 'gemini', '', 'AIzaSyTESTtestTESTtest1234' );
		$ai = Checks::ai();
		$this->assertSame( Checks::OK, $ai['status'] );
		$this->assertStringContainsString( '…1234', $ai['detail'] );
		$this->assertStringNotContainsString( 'TESTtest', $ai['detail'] );
	}

	public function test_bundled_libraries_and_action_scheduler_load(): void {
		$s = $this->statuses();
		$this->assertSame( Checks::OK, $s['libraries'] );
		$this->assertSame( Checks::OK, $s['action_scheduler'] );
		$this->assertTrue( class_exists( \CPub\Publisher\Vendor\League\CommonMark\GithubFlavoredMarkdownConverter::class ) );
		$this->assertFalse( class_exists( \League\CommonMark\CommonMarkConverter::class, false ), 'Unprefixed copy must not be loaded.' );
	}

	public function test_key_states(): void {
		$this->assertSame( Checks::OK, $this->statuses()['encryption_key'] );
		$this->assertSame( Checks::ERROR, Checks::encryption_key( \CPub\Publisher\Crypto\Key::MISSING )['status'] );
		$this->assertSame( Checks::ERROR, Checks::encryption_key( \CPub\Publisher\Crypto\Key::INVALID )['status'] );
	}

	public function test_wordpress_com_app_check(): void {
		$missing = Checks::wpcom_app();
		$this->assertSame( Checks::ERROR, $missing['status'] );
		$this->assertStringContainsString( \CPub\Publisher\Connections\ConnectFlow::redirect_uri(), $missing['detail'], 'says which redirect URL to register' );
		$this->assertStringContainsString( 'CPUB_WPCOM_CLIENT_SECRET', $missing['detail'] );

		add_filter( 'cpub_publisher_wpcom_app', fn() => array( 'client_id' => '999', 'client_secret' => 's' ) );
		$set = Checks::wpcom_app();
		$this->assertSame( Checks::OK, $set['status'] );
		$this->assertStringContainsString( 'App 999 is set', $set['detail'] );
		$this->assertStringNotContainsString( '"s"', $set['detail'], 'the secret is never shown' );
	}

	public function test_https_follows_site_address(): void {
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		$this->assertSame( Checks::ERROR, $this->statuses()['https'] );

		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		$this->assertSame( Checks::OK, $this->statuses()['https'] );
	}

	public function test_cron_warns_when_disabled_by_constant_is_reported_ok_otherwise(): void {
		$this->assertSame( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? Checks::WARN : Checks::OK, $this->statuses()['cron'] );
	}

	public function test_status_page_renders_every_check(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		( new StatusPage() )->render();
		$html = ob_get_clean();

		foreach ( array_keys( $this->statuses() ) as $id ) {
			$this->assertStringContainsString( 'data-check="' . $id . '"', $html );
		}
		$this->assertStringNotContainsString( "define( &#039;CPUB_PUBLISHER_KEY&#039;", $html, 'No new key offered when one is configured.' );
	}

	public function test_sites_page_renders_once(): void {
		global $wp_filter;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\CPub\Publisher\Capabilities::grant();
		set_current_screen( 'dashboard' );
		( new \CPub\Publisher\Admin\Menu() )->add_pages();
		$hook = get_plugin_page_hookname( 'cpub-publisher', '' );
		$this->assertSame( 1, count( $wp_filter[ $hook ]->callbacks[10] ?? array() ), 'Exactly one render callback for the Client sites page.' );
	}

	public function test_menu_respects_capabilities(): void {
		global $menu, $submenu, $_wp_submenu_nopriv, $_registered_pages;
		$menu = $submenu = $_wp_submenu_nopriv = array(); // phpcs:ignore
		Capabilities::grant();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		set_current_screen( 'dashboard' );
		( new \CPub\Publisher\Admin\Menu() )->add_pages();
		$slugs = wp_list_pluck( $submenu['cpub-publisher'] ?? array(), 2 );
		$this->assertContains( 'cpub-publisher', $slugs, 'Editors see Client sites.' );
		$this->assertNotContains( 'cpub-publisher-status', $slugs, 'Editors do not see Status.' );

		$menu = $submenu = array(); // phpcs:ignore
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new \CPub\Publisher\Admin\Menu() )->add_pages();
		$this->assertContains( 'cpub-publisher-status', wp_list_pluck( $submenu['cpub-publisher'], 2 ) );
	}
}
