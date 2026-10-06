<?php

use CPub\Publisher\Connections\ConnectFlow;
use CPub\Publisher\Connections\ConnectorClient;
use CPub\Publisher\Connections\Connections;
use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\Discovery;
use CPub\Publisher\Connections\HealthCheck;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Installer;

/** Regression tests for the independent review of Phase 2. */
class Phase2ReviewTest extends ConnectionsTestCase {

	private function expire_access( int $site_id ): void {
		global $wpdb;
		$wpdb->update( Installer::table( 'credentials' ), array( 'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ), array( 'site_id' => $site_id ) );
	}

	/** @dataProvider not_revocations */
	public function test_non_revocation_answers_keep_the_tokens( int $status, $body ): void {
		$site = $this->connect();
		$this->expire_access( (int) $site->id );
		$this->client->refresh_reply = array( $status, $body );
		$res = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 'cpub_renew_failed', $res->get_error_code() );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
	}

	public static function not_revocations(): array {
		return array(
			'firewall 403'        => array( 403, '<html>Access denied</html>' ),
			'plugin updating 404' => array( 404, array( 'code' => 'rest_no_route' ) ),
			'rate limited 429'    => array( 429, 'Too many requests' ),
			'redirect'            => array( 301, '' ),
			'400 but not grant'   => array( 400, array( 'error' => 'invalid_request' ) ),
		);
	}

	public function test_lost_renewal_reply_is_recovered_by_retrying(): void {
		$site = $this->connect();
		$this->expire_access( (int) $site->id );
		$this->client->lose_next_reply = true; // the site rotates, but its answer never arrives
		$res = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 200, $res['status'], is_wp_error( $res ) ? $res->get_error_message() : '' );
		$this->assertSame( 2, $this->client->refresh_calls );
		$this->assertTrue( $this->client->grant_active, 'No false theft alarm.' );
		$this->assertSame( 200, ConnectorClient::request( Sites::get( (int) $site->id ), 'GET', '/wp/v2/users/me' )['status'] );
	}

	public function test_fresh_token_refused_keeps_the_site_connected_with_a_warning(): void {
		$site = $this->connect();
		// The site accepts renewals but refuses every request (e.g. its agency user was deleted).
		add_filter(
			'pre_http_request',
			$refuse = function ( $pre, $args, $url ) {
				return str_contains( urldecode( $url ), 'rest_route=/wp/v2' ) ? array( 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ), 'body' => '{"code":"cpub_invalid_token"}', 'response' => array( 'code' => 401 ), 'cookies' => array(), 'filename' => null ) : $pre;
			},
			5,
			3
		);
		$res = ConnectorClient::request( $site, 'GET', '/wp/v2/users/me' );
		remove_filter( 'pre_http_request', $refuse, 5 );
		$this->assertSame( 401, $res['status'] );
		$fresh = Sites::get( (int) $site->id );
		$this->assertSame( Sites::CONNECTED, $fresh->status );
		$this->assertStringContainsString( 'renewing it worked', $fresh->last_error );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
	}

	public function test_approval_response_from_another_site_is_ignored(): void {
		$q    = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$code = $this->client->issue_code( $q['code_challenge'] );
		$err  = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => $code, 'iss' => 'https://honest-other-site.test/' ), $this->admin );
		$this->assertSame( 'cpub_issuer', $err->get_error_code() );
		$this->assertNotContains( 'POST ', array_map( fn( $c ) => substr( $c, 0, 5 ), $this->client->calls ), 'The code was not sent anywhere.' );
	}

	public function test_approval_response_without_issuer_is_ignored(): void {
		$q   = self::query_of( ConnectFlow::start( $this->client->url, $this->admin ) );
		$err = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => 'x' ), $this->admin );
		$this->assertSame( 'cpub_issuer', $err->get_error_code() );
	}

	/** @dataProvider off_site */
	public function test_discovery_requires_everything_on_the_site_itself( string $key, $value ): void {
		$this->client->metadata_override = array( $key => $value );
		$this->assertSame( 'cpub_bad_metadata', Discovery::discover( $this->client->url )->get_error_code() );
	}

	public static function off_site(): array {
		return array(
			'other port'        => array( 'token_endpoint', 'https://client.test:8080/wp-json/cpub-connector/v1/oauth/token' ),
			'issuer elsewhere'  => array( 'issuer', 'https://evil.test/' ),
			'userinfo trick'    => array( 'token_endpoint', 'https://evil.test\\@client.test/token' ),
			'old connector'     => array( 'authorization_response_iss_parameter_supported', false ),
		);
	}

	public function test_subdirectory_site_cannot_point_at_a_sibling(): void {
		$this->assertTrue( Discovery::within( 'https://host.test/blog/wp-json/x', 'https://host.test/blog' ) );
		$this->assertFalse( Discovery::within( 'https://host.test/other/wp-json/x', 'https://host.test/blog' ) );
		$this->assertFalse( Discovery::within( 'https://host.test/blogger/x', 'https://host.test/blog' ) );
	}

	public function test_disconnect_waits_for_a_running_renewal(): void {
		global $wpdb;
		$site  = $this->connect();
		$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$lock  = 'cpub_refresh_' . (int) $site->id . '_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		$other->query( "SELECT GET_LOCK('$lock', 0)" );
		add_filter( 'query', $shorten = fn( $q ) => str_replace( ', 20)', ', 1)', $q ) );
		$res = Connections::disconnect( $site );
		remove_filter( 'query', $shorten );
		$other->query( "SELECT RELEASE_LOCK('$lock')" );
		$other->close();
		$this->assertSame( 'cpub_busy', $res->get_error_code() );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status, 'Nothing changed while the renewal held the lock.' );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
	}

	public function test_daily_run_queues_one_spaced_check_per_site(): void {
		$a = $this->connect();
		$b = Sites::create( 'https://second.test', 'Second' );
		Sites::update( $b, array( 'status' => Sites::CONNECTED ) );
		HealthCheck::run_all();
		HealthCheck::run_all(); // not doubled
		$ids = as_get_scheduled_actions( array( 'hook' => HealthCheck::SITE_HOOK, 'status' => 'pending' ), 'ids' );
		$this->assertCount( 2, $ids );
		$times = array_map( fn( $id ) => as_get_datetime_object( ActionScheduler::store()->get_date( $id ) )->getTimestamp(), $ids );
		$this->assertGreaterThanOrEqual( HealthCheck::SPACING, abs( $times[0] - $times[1] ) );
	}

	public function test_overlong_remote_strings_are_cut_to_fit(): void {
		$this->client->metadata_override = array( 'site_name' => str_repeat( 'N', 500 ), 'connector_version' => str_repeat( '9', 50 ) );
		ConnectFlow::start( $this->client->url, $this->admin );
		$site = Sites::find_by_url( $this->client->url );
		$this->assertNotNull( $site, 'The site row must still be saved.' );
		$this->assertSame( 190, mb_strlen( $site->name ) );
		$this->assertSame( 20, strlen( $site->connector_version ) );
		$this->assertNotEmpty( Sites::endpoints( $site )['token_endpoint'] ?? '', 'Endpoints saved despite the long strings.' );
	}
}
