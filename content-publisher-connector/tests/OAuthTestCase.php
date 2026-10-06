<?php

use CPub\Connector\Admin\AuthorizePage;
use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\Installer;
use CPub\Connector\OAuth\Agency;
use CPub\Connector\OAuth\GrantContext;
use CPub\Connector\OAuth\Keys;
use CPub\Connector\PublisherUser;

/**
 * Helpers that drive the Connector the way a real agency client would:
 * consent screen -> code -> token endpoint -> REST requests with the token.
 */
abstract class OAuthTestCase extends WP_UnitTestCase {

	public const CLIENT   = 'cpub-publisher';
	public const REDIRECT = 'https://agency.test/wp-admin/admin.php?page=cpub-publisher&cpub_oauth=callback';
	public const SCOPES   = 'posts:write media:write terms:read account:read';

	protected int $admin;
	protected ?string $redirected = null;

	public function set_up(): void {
		parent::set_up();
		Installer::activate();
		add_filter(
			'cpub_connector_agency',
			fn() => array(
				'client_id'     => self::CLIENT,
				'name'          => 'Test Agency',
				'url'           => 'https://agency.test',
				'logo'          => '',
				'redirect_uris' => array( self::REDIRECT, 'http://127.0.0.1/callback' ),
			)
		);
		Agency::reset();
		add_filter( 'cpub_connector_exit_after_redirect', '__return_false' );
		add_filter(
			'wp_redirect',
			function ( $location ) {
				$this->redirected = $location;
				return false;
			}
		);
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$_SERVER['REQUEST_URI'] = '/wp-json/';
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * With REST_REQUEST defined, core sends HTTP headers during uploads, which
	 * PHPUnit's own output has already made impossible. Ignore only that warning.
	 */
	protected function ignore_header_warnings(): void {
		set_error_handler(
			static fn( $no, $msg ) => str_contains( $msg, 'Cannot modify header information' ),
			E_WARNING
		);
		$this->header_handler = true;
	}

	protected bool $header_handler = false;

	public function tear_down(): void {
		if ( $this->header_handler ) {
			restore_error_handler();
			$this->header_handler = false;
		}
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_CPUB_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_GET = $_POST = $_REQUEST = array(); // phpcs:ignore
		$_SERVER['REQUEST_METHOD'] = 'GET';
		BearerAuth::reset();
		GrantContext::clear();
		Agency::reset();
		parent::tear_down();
	}

	public static function pkce(): array {
		$verifier  = wp_generate_password( 64, false );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return array( $verifier, $challenge );
	}

	protected function authorize_query( array $overrides = array() ): array {
		[ , $challenge ] = self::pkce();
		return array_merge(
			array(
				'page'                  => AuthorizePage::SLUG,
				'response_type'         => 'code',
				'client_id'             => self::CLIENT,
				'redirect_uri'          => self::REDIRECT,
				'scope'                 => self::SCOPES,
				'state'                 => 'state-' . wp_generate_password( 8, false ),
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			$overrides
		);
	}

	/**
	 * Runs the consent page. $decision: null (just view), 'approve' or 'deny'.
	 *
	 * @return array{html: string, redirect: ?string}
	 */
	protected function consent( array $query, ?string $decision = null, ?int $as = null, array $fields = array() ): array {
		wp_set_current_user( $as ?? $this->admin );
		$this->redirected = null;
		$_GET             = $query;
		$_POST            = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		if ( $decision ) {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_POST                     = array( $decision => '1', '_wpnonce' => wp_create_nonce( AuthorizePage::NONCE ) ) + $fields;
			$_REQUEST                  = $_POST;
		}
		$page = new AuthorizePage();
		$page->load();
		ob_start();
		$page->render();
		return array( 'html' => ob_get_clean(), 'redirect' => $this->redirected );
	}

	protected static function query_of( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	protected function token_request( array $params ): WP_REST_Response {
		$this->use_token( null ); // A fresh HTTP request from the agency's server, no bearer token.
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/cpub-connector/v1/oauth/token' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body_params( $params );
		return rest_do_request( $request );
	}

	/** Approve on the consent screen. @return array{0: string, 1: string} code and verifier */
	protected function approve( array $fields = array(), array $query = array() ): array {
		[ $verifier, $challenge ] = self::pkce();
		$result = $this->consent( $this->authorize_query( array( 'code_challenge' => $challenge ) + $query ), 'approve', null, $fields );
		$this->assertNotNull( $result['redirect'], 'Approval should redirect back to the agency. Page said: ' . wp_strip_all_tags( $result['html'] ) );
		return array( self::query_of( $result['redirect'] )['code'], $verifier );
	}

	protected function exchange( string $code, string $verifier ): WP_REST_Response {
		return $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code'          => $code,
				'code_verifier' => $verifier,
			)
		);
	}

	/** Full connect: approve + exchange. Returns the token response data plus the verifier. */
	protected function connect( array $fields = array(), array $query = array() ): array {
		[ $code, $verifier ] = $this->approve( $fields, $query );
		$response = $this->token_request(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLIENT,
				'redirect_uri'  => self::REDIRECT,
				'code'          => $code,
				'code_verifier' => $verifier,
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data() + array( 'code' => $code, 'verifier' => $verifier );
	}

	protected function refresh( string $refresh_token ): WP_REST_Response {
		return $this->token_request(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => self::CLIENT,
				'refresh_token' => $refresh_token,
			)
		);
	}

	/** Makes the next REST request arrive with this bearer token, as over HTTP. */
	protected function use_token( ?string $token, string $header = 'HTTP_AUTHORIZATION' ): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_CPUB_AUTHORIZATION'] );
		if ( null !== $token ) {
			$_SERVER[ $header ] = 'Bearer ' . $token;
			// WordPress defines this once it routes a request to the REST API.
			if ( ! defined( 'REST_REQUEST' ) ) {
				define( 'REST_REQUEST', true );
			}
		}
		BearerAuth::reset();
		$GLOBALS['current_user'] = null;
		wp_get_current_user();
	}

	protected function api( string $method, string $route, array $params = array(), ?string $token = null ): WP_REST_Response {
		if ( null !== $token ) {
			$this->use_token( $token );
		}
		$auth_error = apply_filters( 'rest_authentication_errors', null );
		if ( is_wp_error( $auth_error ) ) {
			return rest_convert_error_to_response( $auth_error );
		}
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_do_request( $request );
	}
}
