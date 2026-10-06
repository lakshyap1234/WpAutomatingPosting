<?php

use CPub\Connector\Admin\StatusPage;
use CPub\Connector\Status\Checks;

class StatusTest extends WP_UnitTestCase {

	public function test_oauth_library_is_loaded_prefixed_only(): void {
		$this->assertSame( Checks::OK, Checks::libraries()['status'] );
		$this->assertFalse( class_exists( \League\OAuth2\Server\AuthorizationServer::class, false ), 'Unprefixed copy must not be loaded.' );
	}

	public function test_https(): void {
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		$this->assertSame( Checks::ERROR, Checks::https()['status'] );
		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		$this->assertSame( Checks::OK, Checks::https()['status'] );
	}

	public function test_page_is_under_settings_for_admins_only(): void {
		global $submenu;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		( new StatusPage() )->add_page();
		$this->assertNotContains( 'cpub-connector', wp_list_pluck( $submenu['options-general.php'] ?? array(), 2 ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		( new StatusPage() )->add_page();
		$this->assertContains( 'cpub-connector', wp_list_pluck( $submenu['options-general.php'], 2 ) );
	}

	public function test_page_renders_all_checks(): void {
		add_filter( 'pre_http_request', fn() => new WP_Error( 'x', 'offline in tests' ) );
		ob_start();
		( new StatusPage() )->render();
		$html = ob_get_clean();
		foreach ( array( 'https', 'libraries', 'authorization_header' ) as $id ) {
			$this->assertStringContainsString( 'data-check="' . $id . '"', $html );
		}
		$this->assertStringContainsString( 'draft', $html );
	}
}
