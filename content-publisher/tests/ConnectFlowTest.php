<?php

use CPub\Publisher\Connections\ConnectFlow;
use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Support\Events;

class ConnectFlowTest extends ConnectionsTestCase {

	public function test_start_builds_a_pkce_approval_url_on_the_client_site(): void {
		$to = ConnectFlow::start( 'client.test', $this->admin );
		$this->assertStringStartsWith( 'https://client.test/wp-admin/admin.php?page=cpub-connector-authorize&', $to );
		$q = self::query_of( $to );
		$this->assertSame( 'code', $q['response_type'] );
		$this->assertSame( 'cpub-publisher', $q['client_id'] );
		$this->assertSame( ConnectFlow::redirect_uri(), $q['redirect_uri'] );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		$this->assertSame( 'posts:write media:write terms:read account:read', $q['scope'] );
		$this->assertGreaterThanOrEqual( 32, strlen( $q['state'] ) );
		$this->assertSame( Sites::PENDING, Sites::find_by_url( 'https://client.test' )->status );
	}

	public function test_redirect_uri_is_the_sites_page(): void {
		$this->assertSame( admin_url( 'admin.php?page=cpub-publisher&cpub_oauth=callback' ), ConnectFlow::redirect_uri() );
	}

	public function test_full_connection(): void {
		$site = $this->connect();
		$this->assertSame( 'Client Test', $site->name );
		$this->assertSame( 'Agency', $site->remote_user, 'Filled in by the first check.' );
		$this->assertNotEmpty( $site->connected_at );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
		$types = array_column( Events::for_site( (int) $site->id ), 'type' );
		$this->assertContains( 'connected', $types );
	}

	public function test_state_is_single_use(): void {
		$q    = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$code = $this->client->issue_code( $q['code_challenge'] );
		ConnectFlow::callback( array( 'state' => $q['state'], 'code' => $code, 'iss' => $this->client->url . '/' ), $this->admin );
		$again = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => $code, 'iss' => $this->client->url . '/' ), $this->admin );
		$this->assertSame( 'cpub_state', $again->get_error_code() );
	}

	public function test_unknown_state_is_refused(): void {
		$err = ConnectFlow::callback( array( 'state' => 'forged', 'code' => 'x' ), $this->admin );
		$this->assertSame( 'cpub_state', $err->get_error_code() );
		$this->assertSame( array(), $this->client->calls, 'Nothing sent to any site.' );
	}

	public function test_callback_by_a_different_staff_member_is_refused(): void {
		$q     = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$err   = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => 'x' ), $other );
		$this->assertSame( 'cpub_state', $err->get_error_code() );
	}

	public function test_denied_approval(): void {
		$q   = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$err = ConnectFlow::callback( array( 'state' => $q['state'], 'error' => 'access_denied', 'iss' => $this->client->url . '/' ), $this->admin );
		$this->assertSame( 'cpub_denied', $err->get_error_code() );
		$site = Sites::find_by_url( $this->client->url );
		$this->assertSame( Sites::DISCONNECTED, $site->status );
		$this->assertStringContainsString( 'denied', $site->last_error );
	}

	public function test_failed_exchange_leaves_the_site_unconnected(): void {
		$q   = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$err = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => 'not-a-real-code', 'iss' => $this->client->url . '/' ), $this->admin );
		$this->assertSame( 'cpub_exchange', $err->get_error_code() );
		$site = Sites::find_by_url( $this->client->url );
		$this->assertNotSame( Sites::CONNECTED, $site->status );
		$this->assertNull( Credentials::load( (int) $site->id ) );
	}

	public function test_refuses_http_and_self(): void {
		$this->assertSame( 'cpub_bad_url', ConnectFlow::start( 'http://client.test', $this->admin )->get_error_code() );
		update_option( 'home', 'https://agency.test' );
		$this->assertSame( 'cpub_self', ConnectFlow::start( 'https://agency.test', $this->admin )->get_error_code() );
	}

	public function test_reconnecting_keeps_one_row(): void {
		$first  = $this->connect();
		$second = $this->connect();
		$this->assertSame( (int) $first->id, (int) $second->id );
		$this->assertCount( 1, Sites::all() );
	}
}
