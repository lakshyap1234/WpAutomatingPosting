<?php

use CPub\Connector\ActivityLog;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Installer;

/** Token endpoint: code exchange, PKCE, refresh rotation, reuse detection, revocation. */
class TokenTest extends OAuthTestCase {

	public function test_metadata_describes_the_endpoints(): void {
		$data = rest_do_request( new WP_REST_Request( 'GET', '/cpub-connector/v1/oauth/metadata' ) )->get_data();
		$this->assertSame( array( 'S256' ), $data['code_challenge_methods_supported'] );
		$this->assertStringContainsString( 'page=cpub-connector-authorize', $data['authorization_endpoint'] );
		$this->assertStringContainsString( 'cpub-connector/v1/oauth/token', $data['token_endpoint'] );
		$this->assertSame( array( 'posts:write', 'media:write', 'terms:read', 'terms:write', 'account:read' ), $data['scopes_supported'] );
	}

	public function test_code_exchange_issues_tokens_and_activates_connection(): void {
		$tokens = $this->connect();
		$this->assertSame( 'Bearer', $tokens['token_type'] );
		$this->assertSame( 3600, $tokens['expires_in'] );
		$this->assertStringStartsWith( TokenStore::ACCESS_PREFIX, $tokens['access_token'] );
		$this->assertNotEmpty( $tokens['refresh_token'] );
		$grant = Grants::active();
		$this->assertNotNull( $grant );
		$this->assertSame( $this->admin, (int) $grant->approved_by );
	}

	public function test_only_hashes_are_stored(): void {
		global $wpdb;
		$tokens = $this->connect();
		$raw_id = substr( $tokens['access_token'], strlen( TokenStore::ACCESS_PREFIX ) );
		$dump   = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Installer::table( 'tokens' ) ) );
		$this->assertStringNotContainsString( $raw_id, $dump );
		$this->assertStringContainsString( hash( 'sha256', $raw_id ), $dump );
	}

	public function test_wrong_verifier_is_refused(): void {
		$page = $this->consent( $this->authorize_query(), 'approve' );
		$res  = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code'          => self::query_of( $page['redirect'] )['code'],
				'code_verifier' => str_repeat( 'x', 64 ),
			)
		);
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'invalid_grant', $res->get_data()['error'] );
		$this->assertNull( Grants::active() );
	}

	public function test_missing_verifier_is_refused(): void {
		$page = $this->consent( $this->authorize_query(), 'approve' );
		$res  = $this->token_request(
			array(
				'grant_type'   => 'authorization_code',
				'client_id'    => self::CLIENT,
				'redirect_uri' => self::REDIRECT,
				'code'         => self::query_of( $page['redirect'] )['code'],
			)
		);
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_code_reuse_is_refused_and_cuts_off_the_connection(): void {
		$tokens = $this->connect();
		$again  = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code'          => $tokens['code'],
				'code_verifier' => $tokens['verifier'],
			)
		);
		$this->assertSame( 400, $again->get_status() );
		$this->assertNull( Grants::active() );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $tokens['access_token'] )->get_status() );
	}

	public function test_expired_code_is_refused(): void {
		global $wpdb;
		$page = $this->consent( $this->authorize_query(), 'approve' );
		$wpdb->query( 'UPDATE ' . Installer::table( 'tokens' ) . " SET expires_at = '2000-01-01 00:00:00'" );
		[ $verifier ] = self::pkce();
		$res = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code'          => self::query_of( $page['redirect'] )['code'],
				'code_verifier' => $verifier,
			)
		);
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_code_for_one_return_address_cannot_be_used_with_another(): void {
		[ $verifier, $challenge ] = self::pkce();
		$page = $this->consent( $this->authorize_query( array( 'code_challenge' => $challenge ) ), 'approve' );
		$res  = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLIENT,
				'redirect_uri'  => 'http://127.0.0.1/callback',
				'code'          => self::query_of( $page['redirect'] )['code'],
				'code_verifier' => $verifier,
			)
		);
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_refresh_rotates_tokens_and_retires_the_old_access_token(): void {
		$first = $this->connect();
		$res   = $this->refresh( $first['refresh_token'] );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$second = $res->get_data();
		$this->assertNotSame( $first['access_token'], $second['access_token'] );
		$this->assertNotSame( $first['refresh_token'], $second['refresh_token'] );

		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $first['access_token'] )->get_status() );
		$this->assertSame( 200, $this->api( 'GET', '/wp/v2/users/me', array(), $second['access_token'] )->get_status() );
	}

	public function test_reusing_an_old_refresh_token_cuts_off_the_connection(): void {
		$first  = $this->connect();
		$second = $this->refresh( $first['refresh_token'] )->get_data();
		$third  = $this->refresh( $second['refresh_token'] )->get_data(); // the agency carries on normally

		$replay = $this->refresh( $first['refresh_token'] );             // a stolen copy of the first token
		$this->assertSame( 400, $replay->get_status() );

		$this->assertNull( Grants::active(), 'Replay of a used refresh token must revoke the connection.' );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $third['access_token'] )->get_status() );
		$this->assertSame( 400, $this->refresh( $third['refresh_token'] )->get_status() );

		$reasons = array_column( ActivityLog::recent(), 'detail' );
		$this->assertContains( Grants::REASONS['refresh_token_reuse'], $reasons );
	}

	public function test_new_approval_replaces_old_connection(): void {
		$old = $this->connect();
		$new = $this->connect();
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $old['access_token'] )->get_status() );
		$this->assertSame( 200, $this->api( 'GET', '/wp/v2/users/me', array(), $new['access_token'] )->get_status() );
		$this->assertSame( 400, $this->refresh( $old['refresh_token'] )->get_status() );
	}

	/** @dataProvider revocable */
	public function test_revocation_endpoint_ends_the_connection( string $which ): void {
		$tokens  = $this->connect();
		$request = new WP_REST_Request( 'POST', '/cpub-connector/v1/oauth/revoke' );
		$request->set_body_params( array( 'client_id' => self::CLIENT, 'token' => $tokens[ $which ] ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertNull( Grants::active() );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $tokens['access_token'] )->get_status() );
	}

	public static function revocable(): array {
		return array( 'access token' => array( 'access_token' ), 'refresh token' => array( 'refresh_token' ) );
	}

	public function test_revocation_with_unknown_token_still_answers_200_and_changes_nothing(): void {
		$this->connect();
		$request = new WP_REST_Request( 'POST', '/cpub-connector/v1/oauth/revoke' );
		$request->set_body_params( array( 'client_id' => self::CLIENT, 'token' => 'cpub_at_nonsense' ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertNotNull( Grants::active() );
	}

	public function test_token_responses_are_not_cacheable(): void {
		$page = $this->consent( $this->authorize_query(), 'approve' );
		$res  = $this->token_request( array( 'grant_type' => 'authorization_code', 'client_id' => self::CLIENT, 'code' => 'x' ) );
		$this->assertSame( 'no-store', $res->get_headers()['Cache-Control'] );
	}

	public function test_unsupported_grant_types_are_refused(): void {
		foreach ( array( 'password', 'client_credentials', 'implicit' ) as $type ) {
			$res = $this->token_request( array( 'grant_type' => $type, 'client_id' => self::CLIENT, 'username' => 'admin', 'password' => 'x' ) );
			$this->assertSame( 'unsupported_grant_type', $res->get_data()['error'], $type );
		}
	}
}
