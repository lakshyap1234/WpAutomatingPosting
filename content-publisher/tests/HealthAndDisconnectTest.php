<?php

use CPub\Publisher\Admin\SitesPage;
use CPub\Publisher\Connections\Connections;
use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\HealthCheck;
use CPub\Publisher\Connections\Sites;

class HealthAndDisconnectTest extends ConnectionsTestCase {

	public function test_check_ok_records_time_and_clears_errors(): void {
		$site = $this->connect();
		Sites::update( (int) $site->id, array( 'last_error' => 'old problem', 'last_check_at' => null ) );
		$this->assertTrue( HealthCheck::check( Sites::get( (int) $site->id ) ) );
		$fresh = Sites::get( (int) $site->id );
		$this->assertNull( $fresh->last_error );
		$this->assertNotEmpty( $fresh->last_check_at );
	}

	public function test_check_flags_an_unreachable_site_but_keeps_it_connected(): void {
		$site                  = $this->connect();
		$this->client->offline = true;
		$this->assertFalse( HealthCheck::check( $site ) );
		$fresh = Sites::get( (int) $site->id );
		$this->assertSame( Sites::CONNECTED, $fresh->status );
		$this->assertStringContainsString( 'timed out', $fresh->last_error );
	}

	public function test_check_detects_a_disconnect_on_the_client_side(): void {
		$site = $this->connect();
		$this->client->revoke(); // on WordPress.com, or Jetpack disconnected
		HealthCheck::run_all();                                 // queues the check
		HealthCheck::check_site( (int) $site->id );             // what the queued action runs
		$this->assertSame( Sites::DISCONNECTED, Sites::get( (int) $site->id )->status );
	}

	public function test_daily_check_is_scheduled_once(): void {
		HealthCheck::schedule();
		HealthCheck::schedule();
		$this->assertTrue( as_has_scheduled_action( HealthCheck::HOOK, array(), HealthCheck::GROUP ) );
		$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => HealthCheck::HOOK, 'status' => 'pending' ), 'ids' ) );
	}

	public function test_disconnect_forgets_the_token(): void {
		$site  = $this->connect();
		$calls = count( $this->client->calls );
		$this->assertTrue( Connections::disconnect( $site ) );
		$this->assertCount( $calls, $this->client->calls, 'WordPress.com has nothing to call: the token is just forgotten' );
		$this->assertNull( Credentials::load( (int) $site->id ) );
		$this->assertSame( Sites::DISCONNECTED, Sites::get( (int) $site->id )->status );
	}

	public function test_disconnect_works_offline(): void {
		$site                  = $this->connect();
		$this->client->offline = true;
		$this->assertTrue( Connections::disconnect( $site ) );
		$this->assertNull( Credentials::load( (int) $site->id ) );
	}

	public function test_check_records_what_the_approver_may_do(): void {
		$site = $this->connect();
		$this->assertSame( 0, (int) $site->can_publish );
		$this->client->can_publish = true; // e.g. made an Author on the site
		HealthCheck::check( $site );
		$site = Sites::get( (int) $site->id );
		$this->assertSame( 1, (int) $site->can_publish );
		$this->assertSame( 'author', $site->remote_role );
		$this->client->admin = true;
		HealthCheck::check( $site );
		$this->assertSame( 'administrator', Sites::get( (int) $site->id )->remote_role );
	}

	public function test_check_flags_an_approver_who_cannot_upload(): void {
		$site                     = $this->connect();
		$this->client->can_upload = false;
		$this->assertFalse( HealthCheck::check( $site ) );
		$site = Sites::get( (int) $site->id );
		$this->assertSame( Sites::CONNECTED, $site->status );
		$this->assertStringContainsString( 'can\'t write posts or upload images', $site->last_error );
	}

	public function test_page_warns_when_an_administrator_approved(): void {
		$this->client->admin = true;
		$this->connect();
		ob_start();
		( new SitesPage() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'cpub-admin-warning', $html );
		$this->assertStringContainsString( '(Administrator)', $html );
	}

	public function test_page_does_not_warn_for_an_author(): void {
		$this->client->can_publish = true;
		$this->connect();
		ob_start();
		( new SitesPage() )->render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'cpub-admin-warning', $html );
		$this->assertStringContainsString( '(Author)', $html );
	}

	public function test_upgrading_retires_connector_connections(): void {
		$old = Sites::create( 'https://old-connector.test', 'Old' );
		Sites::update( $old, array( 'status' => Sites::CONNECTED ) );
		Credentials::save( $old, 'cpub_at_from_the_connector' );
		$new = $this->connect();
		$this->assertSame( 1, Connections::retire_connector_sites() );
		$old = Sites::get( $old );
		$this->assertSame( Sites::DISCONNECTED, $old->status );
		$this->assertStringContainsString( 'Jetpack', $old->last_error );
		$this->assertNull( Credentials::load( (int) $old->id ) );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $new->id )->status, 'Jetpack sites are left alone' );
	}

	public function test_remove_only_when_disconnected(): void {
		$site = $this->connect();
		$this->assertSame( 'cpub_connected', Connections::remove( $site )->get_error_code() );
		Connections::disconnect( $site );
		$this->assertTrue( Connections::remove( Sites::get( (int) $site->id ) ) );
		$this->assertNull( Sites::get( (int) $site->id ) );
	}

	public function test_actions_need_manage_capability_and_nonce(): void {
		$site = $this->connect();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST = $_POST = array( 'action' => 'cpub_site_disconnect', 'site_id' => $site->id, '_wpnonce' => wp_create_nonce( 'cpub_site_disconnect' ) ); // phpcs:ignore
		try {
			SitesPage::handle();
			$this->fail( 'Editor must not disconnect.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status );
		}

		wp_set_current_user( $this->admin );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = 'forged'; // phpcs:ignore
		try {
			SitesPage::handle();
			$this->fail( 'Forged nonce must be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status );
		}

		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce( 'cpub_site_disconnect' ); // phpcs:ignore
		SitesPage::handle();
		$this->assertSame( Sites::DISCONNECTED, Sites::get( (int) $site->id )->status );
		$_REQUEST = $_POST = array(); // phpcs:ignore
	}

	public function test_callback_needs_manage_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_GET = array( 'page' => 'cpub-publisher', 'cpub_oauth' => 'callback', 'state' => 'x', 'code' => 'y' );
		$this->expectException( WPDieException::class );
		try {
			SitesPage::load();
		} finally {
			$_GET = array();
		}
	}

	public function test_page_lists_sites_with_actions_for_managers_only(): void {
		$site = $this->connect();
		ob_start();
		( new SitesPage() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-status="connected"', $html );
		$this->assertStringContainsString( 'data-action="cpub_site_disconnect"', $html );
		$this->assertStringContainsString( 'id="cpub-connect"', $html );
		$this->assertStringNotContainsString( 'id="cpub-no-app"', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		\CPub\Publisher\Capabilities::grant();
		ob_start();
		( new SitesPage() )->render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'data-status="connected"', $html );
		$this->assertStringNotContainsString( 'cpub_site_disconnect', $html );
		$this->assertStringNotContainsString( 'id="cpub-connect"', $html );
	}
}
