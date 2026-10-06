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
		$site                       = $this->connect();
		$this->client->grant_active = false;
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

	public function test_disconnect_revokes_on_the_site_and_forgets_tokens(): void {
		$site    = $this->connect();
		$refresh = Credentials::load( (int) $site->id )['refresh'];
		$this->assertTrue( Connections::disconnect( $site ) );
		$this->assertSame( $refresh, $this->client->last['body']['token'] );
		$this->assertFalse( $this->client->grant_active );
		$this->assertNull( Credentials::load( (int) $site->id ) );
		$this->assertSame( Sites::DISCONNECTED, Sites::get( (int) $site->id )->status );
	}

	public function test_disconnect_still_forgets_tokens_if_the_site_is_down(): void {
		$site                  = $this->connect();
		$this->client->offline = true;
		$r                     = Connections::disconnect( $site );
		$this->assertSame( 'cpub_revoke_failed', $r->get_error_code() );
		$this->assertNull( Credentials::load( (int) $site->id ) );
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
