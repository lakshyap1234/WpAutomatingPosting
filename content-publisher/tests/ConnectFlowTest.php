<?php

use CPub\Publisher\Connections\ConnectFlow;
use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\Wpcom;
use CPub\Publisher\Support\Events;

/** Connecting a client site through WordPress.com (Jetpack). */
class ConnectFlowTest extends ConnectionsTestCase {

	public function test_start_sends_the_browser_to_wordpress_com_for_that_one_site(): void {
		$to = ConnectFlow::start( 'client.test', $this->admin );
		$this->assertStringStartsWith( Wpcom::AUTHORIZE . '?', $to );
		$q = self::query_of( $to );
		$this->assertSame( 'code', $q['response_type'] );
		$this->assertSame( FakeWpcom::CLIENT_ID, $q['client_id'] );
		$this->assertSame( ConnectFlow::redirect_uri(), $q['redirect_uri'] );
		$this->assertSame( (string) $this->client->site_id, $q['blog'], 'a token for this site only' );
		$this->assertArrayNotHasKey( 'scope', $q, 'no global access' );
		$this->assertArrayNotHasKey( 'client_secret', $q, 'the secret never goes to the browser' );
		$this->assertGreaterThanOrEqual( 32, strlen( $q['state'] ) );
		$site = Sites::find_by_url( 'https://client.test' );
		$this->assertSame( Sites::PENDING, $site->status );
		$this->assertSame( $this->client->site_id, (int) $site->wpcom_site_id );
		$this->assertSame( 'Client Test', $site->name );
	}

	public function test_redirect_uri_is_the_sites_page(): void {
		$this->assertSame( admin_url( 'admin.php?page=cpub-publisher&cpub_oauth=callback' ), ConnectFlow::redirect_uri() );
	}

	public function test_full_connection(): void {
		$site = $this->connect();
		$this->assertSame( 'Client Test', $site->name );
		$this->assertSame( 'Agency', $site->remote_user, 'filled in by the first check' );
		$this->assertSame( 'contributor', $site->remote_role, 'can write, can\'t publish' );
		$this->assertSame( 0, (int) $site->can_publish );
		$this->assertNotEmpty( $site->connected_at );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
		$this->assertContains( 'connected', array_column( Events::for_site( (int) $site->id ), 'type' ) );
		// The secret went to WordPress.com, server to server, with the code.
		$this->assertContains( 'POST /oauth2/token', $this->client->calls );
	}

	public function test_without_the_app_nothing_starts(): void {
		add_filter( 'cpub_publisher_wpcom_app', fn() => array( 'client_id' => '', 'client_secret' => '' ), 20 );
		$this->assertSame( 'cpub_no_app', ConnectFlow::start( $this->client->url, $this->admin )->get_error_code() );
		$this->assertSame( array(), $this->client->calls );
	}

	public function test_a_site_wordpress_com_does_not_know_is_refused(): void {
		$this->client->known = false;
		$err                 = ConnectFlow::start( $this->client->url, $this->admin );
		$this->assertSame( 'cpub_no_jetpack', $err->get_error_code() );
		$this->assertStringContainsString( 'Jetpack', $err->get_error_message() );
		$this->assertNull( Sites::find_by_url( $this->client->url ), 'nothing added to the list' );
	}

	public function test_wordpress_com_unreachable(): void {
		$this->client->offline = true;
		$this->assertSame( 'cpub_unreachable', ConnectFlow::start( $this->client->url, $this->admin )->get_error_code() );
	}

	public function test_state_is_single_use(): void {
		$q    = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$code = $this->client->issue_code();
		ConnectFlow::callback( array( 'state' => $q['state'], 'code' => $code ), $this->admin );
		$again = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => $code ), $this->admin );
		$this->assertSame( 'cpub_state', $again->get_error_code() );
	}

	public function test_unknown_state_is_refused(): void {
		$err = ConnectFlow::callback( array( 'state' => 'forged', 'code' => 'x' ), $this->admin );
		$this->assertSame( 'cpub_state', $err->get_error_code() );
		$this->assertSame( array(), $this->client->calls, 'the code was sent nowhere' );
	}

	public function test_callback_by_a_different_staff_member_is_refused(): void {
		$q     = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$err   = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => 'x' ), $other );
		$this->assertSame( 'cpub_state', $err->get_error_code() );
	}

	public function test_denied_approval(): void {
		$q   = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$err = ConnectFlow::callback( array( 'state' => $q['state'], 'error' => 'access_denied' ), $this->admin );
		$this->assertSame( 'cpub_denied', $err->get_error_code() );
		$site = Sites::find_by_url( $this->client->url );
		$this->assertSame( Sites::DISCONNECTED, $site->status );
		$this->assertStringContainsString( 'denied', $site->last_error );
	}

	public function test_failed_exchange_leaves_the_site_unconnected(): void {
		$q   = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$err = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => 'not-a-real-code' ), $this->admin );
		$this->assertSame( 'cpub_exchange', $err->get_error_code() );
		$site = Sites::find_by_url( $this->client->url );
		$this->assertNotSame( Sites::CONNECTED, $site->status );
		$this->assertNull( Credentials::load( (int) $site->id ) );
	}

	public function test_approval_for_another_site_is_not_kept(): void {
		$q                         = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$this->client->approve_for = 777; // they picked another of their sites on the approval screen
		$err                       = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => $this->client->issue_code() ), $this->admin );
		$this->assertSame( 'cpub_wrong_site', $err->get_error_code() );
		$this->assertStringContainsString( 'other.example', $err->get_error_message() );
		$site = Sites::find_by_url( $this->client->url );
		$this->assertNotSame( Sites::CONNECTED, $site->status );
		$this->assertNull( Credentials::load( (int) $site->id ), 'the token wasn\'t stored' );
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

	public function test_the_same_site_under_another_address_is_not_added_twice(): void {
		$this->connect();
		$this->client->url = 'https://www.client.test'; // WordPress.com knows it under this address too
		$err               = ConnectFlow::start( 'https://www.client.test', $this->admin );
		$this->assertSame( 'cpub_duplicate', $err->get_error_code() );
		$this->assertCount( 1, Sites::all() );
	}
}
