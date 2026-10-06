<?php

use CPub\Connector\Rest\AuthorizationProbe;
use CPub\Connector\Status\Checks;

class AuthorizationProbeTest extends WP_UnitTestCase {

	private const PATH = '/cpub-connector/v1/diagnostics/authorization';

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	private function call( ?string $authorization ): array {
		$request = new WP_REST_Request( 'GET', self::PATH );
		if ( null !== $authorization ) {
			$request->set_header( 'Authorization', $authorization );
		}
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function expect_secret( string $secret ): void {
		set_transient( AuthorizationProbe::TRANSIENT, hash( 'sha256', $secret ), 120 );
	}

	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( self::PATH, rest_get_server()->get_routes() );
	}

	public function test_received_when_the_expected_secret_arrives(): void {
		$this->expect_secret( 'abc123' );
		$this->assertSame( array( 'authorization' => 'received', 'source' => 'header' ), $this->call( 'Bearer abc123' ) );
		$this->assertFalse( get_transient( AuthorizationProbe::TRANSIENT ), 'The secret is single-use.' );
	}

	public function test_missing_without_header(): void {
		$this->expect_secret( 'abc123' );
		$this->assertSame( 'missing', $this->call( null )['authorization'] );
	}

	public function test_wrong_secret_reveals_nothing(): void {
		$this->expect_secret( 'abc123' );
		$this->assertSame( array( 'authorization' => 'missing', 'source' => null ), $this->call( 'Bearer guess' ) );
	}

	public function test_no_probe_in_progress(): void {
		$this->assertSame( 'missing', $this->call( 'Bearer abc123' )['authorization'] );
	}

	public function test_basic_auth_is_not_a_bearer_token(): void {
		$this->expect_secret( 'abc123' );
		$this->assertSame( 'missing', $this->call( 'Basic abc123' )['authorization'] );
	}

	public function test_falls_back_to_redirect_http_authorization(): void {
		// Apache + CGI with a rewrite rule puts the header here instead.
		$this->expect_secret( 'abc123' );
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer abc123';
		$this->assertSame( array( 'authorization' => 'received', 'source' => 'REDIRECT_HTTP_AUTHORIZATION' ), $this->call( null ) );
	}

	/**
	 * Runs the whole loopback check, with the HTTP call routed straight into the
	 * REST server. $strip simulates a host that drops the header.
	 */
	private function run_loopback( bool $strip ): array {
		$sent = null;
		$intercept = function ( $pre, $args, $url ) use ( $strip, &$sent ) {
			$sent    = $args['headers']['Authorization'] ?? null;
			$request = new WP_REST_Request( 'GET', self::PATH );
			if ( ! $strip && $sent ) {
				$request->set_header( 'Authorization', $sent );
			}
			$response = rest_do_request( $request );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $response->get_data() ),
				'response' => array( 'code' => $response->get_status(), 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $intercept, 10, 3 );
		$result = AuthorizationProbe::run();
		remove_filter( 'pre_http_request', $intercept );
		$this->assertMatchesRegularExpression( '/^Bearer [A-Za-z0-9]{40}$/', (string) $sent );
		return $result;
	}

	public function test_run_reports_received_on_a_good_host(): void {
		$this->assertSame( AuthorizationProbe::RECEIVED, $this->run_loopback( false )['result'] );
		$this->assertFalse( get_transient( AuthorizationProbe::TRANSIENT ) );
	}

	public function test_run_reports_missing_when_host_strips_header(): void {
		$result = $this->run_loopback( true );
		$this->assertSame( AuthorizationProbe::MISSING, $result['result'] );
		$this->assertSame( Checks::WARN, Checks::authorization_header( $result )['status'], 'Backup header keeps it working, so a warning, not an error.' );
		$this->assertStringContainsString( 'SetEnvIf Authorization', Checks::authorization_header( $result )['detail'] );
	}

	public function test_run_reports_unreachable_when_loopback_fails(): void {
		$fail = fn() => new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		add_filter( 'pre_http_request', $fail );
		$result = AuthorizationProbe::run();
		remove_filter( 'pre_http_request', $fail );

		$this->assertSame( AuthorizationProbe::UNREACHABLE, $result['result'] );
		$check = Checks::authorization_header( $result );
		$this->assertSame( Checks::WARN, $check['status'] );
		$this->assertStringContainsString( 'Failed to connect', $check['detail'] );
		$this->assertFalse( get_transient( AuthorizationProbe::TRANSIENT ), 'Secret is cleared even on failure.' );
	}

	public function test_run_reports_unreachable_when_rest_api_is_blocked(): void {
		$blocked = fn() => array( 'headers' => array(), 'body' => '<html>Forbidden</html>', 'response' => array( 'code' => 403, 'message' => 'Forbidden' ), 'cookies' => array(), 'filename' => null );
		add_filter( 'pre_http_request', $blocked );
		$result = AuthorizationProbe::run();
		remove_filter( 'pre_http_request', $blocked );
		$this->assertSame( AuthorizationProbe::UNREACHABLE, $result['result'] );
		$this->assertStringContainsString( 'HTTP 403', $result['detail'] );
	}
}
