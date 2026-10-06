<?php

use CPub\Publisher\Connections\ConnectorClient;
use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Installer;

class ConnectorClientTest extends ConnectionsTestCase {

	private function expire_access( int $site_id ): void {
		global $wpdb;
		$wpdb->update( Installer::table( 'credentials' ), array( 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'site_id' => $site_id ) );
	}

	public function test_sends_the_token_in_both_headers(): void {
		$site = $this->connect();
		$res  = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 200, $res['status'] );
		$h = $this->client->last['headers'];
		$this->assertStringStartsWith( 'Bearer cpub_at_', $h['authorization'] );
		$this->assertSame( $h['authorization'], $h['x-cpub-authorization'] );
	}

	public function test_renews_an_expiring_token_once_and_stores_the_new_pair(): void {
		$site   = $this->connect();
		$before = Credentials::load( (int) $site->id );
		$this->expire_access( (int) $site->id );

		$this->assertSame( 200, ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' )['status'] );
		$this->assertSame( 1, $this->client->refresh_calls );
		$after = Credentials::load( (int) $site->id );
		$this->assertNotSame( $before['refresh'], $after['refresh'], 'The rotated refresh token must be saved.' );

		ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 1, $this->client->refresh_calls, 'No renewal while the new token is fresh.' );
	}

	public function test_a_401_renews_and_retries(): void {
		$site                 = $this->connect();
		$this->client->access = array(); // the site forgot our access token (e.g. its own restart)
		$res                  = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 200, $res['status'] );
		$this->assertSame( 1, $this->client->refresh_calls );
	}

	public function test_disconnected_on_the_client_side_is_detected(): void {
		$site                       = $this->connect();
		$this->client->grant_active = false; // their admin clicked Disconnect
		$res                        = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 'cpub_disconnected', $res->get_error_code() );
		$fresh = Sites::get( (int) $site->id );
		$this->assertSame( Sites::DISCONNECTED, $fresh->status );
		$this->assertStringContainsString( 'no longer accepts', $fresh->last_error );
		$this->assertNull( Credentials::load( (int) $site->id ), 'Dead tokens are deleted.' );
	}

	public function test_server_trouble_during_renewal_keeps_the_tokens(): void {
		$site                      = $this->connect();
		$this->expire_access( (int) $site->id );
		$this->client->refresh_500 = true;
		$res                       = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 'cpub_renew_failed', $res->get_error_code() );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
	}

	public function test_network_failure_keeps_the_connection(): void {
		$site                  = $this->connect();
		$this->client->offline = true;
		$this->assertWPError( ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' ) );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status );
	}

	public function test_renewal_skipped_if_another_process_already_renewed(): void {
		$site  = $this->connect();
		$stale = Credentials::load( (int) $site->id )['access'];
		// Another process renewed while we waited for the lock:
		Credentials::save( (int) $site->id, 'cpub_at_fresh_from_other_process', 'rt_other', 3600 );
		$this->assertSame( 'cpub_at_fresh_from_other_process', ConnectorClient::renew( $site, $stale ) );
		$this->assertSame( 0, $this->client->refresh_calls, 'Must not spend the refresh token a second time.' );
	}

	public function test_renewal_waits_for_the_lock(): void {
		global $wpdb;
		$site = $this->connect();
		$this->expire_access( (int) $site->id );
		// Hold the site's lock from a second database connection, as a parallel request would.
		$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$lock  = 'cpub_refresh_' . (int) $site->id . '_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		$this->assertSame( '1', (string) $other->query( "SELECT GET_LOCK('$lock', 0)" )->fetch_row()[0] );

		add_filter( 'query', $shorten = fn( $q ) => str_replace( 'GET_LOCK(\'' . $lock . '\', 20)', 'GET_LOCK(\'' . $lock . '\', 1)', $q ) );
		$res = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		remove_filter( 'query', $shorten );
		$other->query( "SELECT RELEASE_LOCK('$lock')" );
		$other->close();

		$this->assertSame( 'cpub_busy', $res->get_error_code() );
		$this->assertSame( 0, $this->client->refresh_calls );
	}

	public function test_non_get_post_methods_travel_as_post_with_override(): void {
		$site = $this->connect();
		$this->client->delete_405 = true; // host refuses the DELETE method itself
		$this->client->media[5]   = array( 'id' => 5 );
		$res  = ConnectorClient::request( $site, 'DELETE', '/wp/v2/media/5' );
		$this->assertSame( 200, $res['status'] );
		$this->assertSame( 'POST', $this->client->last['http_method'] );
		$this->assertSame( 'DELETE', $this->client->last['method'] );
	}

	public function test_json_body(): void {
		$site = $this->connect();
		ConnectorClient::request( $site, 'POST', '/wp/v2/posts', array( 'json' => array( 'title' => 'Hi' ) ) );
		$this->assertSame( array( 'title' => 'Hi' ), $this->client->last['body'] );
		$this->assertSame( 'application/json', $this->client->last['headers']['content-type'] );
	}

	public function test_refuses_when_not_connected(): void {
		$site = $this->connect();
		Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED ) );
		$this->assertSame( 'cpub_not_connected', ConnectorClient::request( Sites::get( (int) $site->id ), 'GET', '/x' )->get_error_code() );
	}
}
