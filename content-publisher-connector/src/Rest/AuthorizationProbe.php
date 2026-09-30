<?php
/**
 * Checks whether "Authorization: Bearer …" headers reach WordPress on this host.
 *
 * OAuth access tokens arrive in that header. Some server setups (Apache with
 * PHP as CGI/FastCGI, some proxies) strip it, and every request from the
 * Publisher would then fail as unauthenticated. This finds out before a
 * client connects, rather than after.
 *
 * How: the Status page creates a one-off secret, stores its hash for two
 * minutes, and calls this site's own endpoint with the secret as a Bearer
 * token. The endpoint reports whether the same secret arrived. Without the
 * secret the endpoint reveals nothing beyond "not received".
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Rest;

use CPub\Connector\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthorizationProbe {

	public const ROUTE     = '/diagnostics/authorization';
	public const TRANSIENT = 'cpub_connector_auth_probe';

	public const RECEIVED    = 'received';
	public const MISSING     = 'missing';
	public const UNREACHABLE = 'unreachable';

	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'respond' ),
				// Public on purpose: the loopback request carries no login cookie.
				// It only confirms a secret the site itself created moments ago.
				'permission_callback' => '__return_true',
			)
		);
	}

	public function respond( \WP_REST_Request $request ): \WP_REST_Response {
		$expected = get_transient( self::TRANSIENT );
		[ $token, $source ] = self::bearer_token( $request );

		$ok = is_string( $expected ) && null !== $token && hash_equals( $expected, hash( 'sha256', $token ) );
		if ( $ok ) {
			delete_transient( self::TRANSIENT );
		}
		return new \WP_REST_Response(
			array(
				'authorization' => $ok ? self::RECEIVED : self::MISSING,
				'source'        => $ok ? $source : null,
			),
			200
		);
	}

	/**
	 * The Bearer token from the request, looking where different servers put it.
	 * The OAuth server (Phase 1) will read the header the same way.
	 *
	 * @return array{0: ?string, 1: ?string} token and where it was found
	 */
	public static function bearer_token( \WP_REST_Request $request ): array {
		$candidates = array(
			'header'                      => $request->get_header( 'authorization' ),
			'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);
		foreach ( $candidates as $source => $value ) {
			if ( is_string( $value ) && preg_match( '/^Bearer\s+(\S+)$/i', trim( $value ), $m ) ) {
				return array( $m[1], $source );
			}
		}
		return array( null, null );
	}

	/**
	 * Runs the check against this site.
	 *
	 * @return array{result: string, detail: string}
	 */
	public static function run(): array {
		$secret = wp_generate_password( 40, false );
		set_transient( self::TRANSIENT, hash( 'sha256', $secret ), 2 * MINUTE_IN_SECONDS );

		$response = wp_remote_get(
			rest_url( Plugin::REST_NAMESPACE . self::ROUTE ),
			array(
				'timeout'   => 10,
				'headers'   => array(
					'Authorization' => 'Bearer ' . $secret,
					'Cache-Control' => 'no-cache',
				),
				// Same setting WordPress's Site Health loopback test uses.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			)
		);
		delete_transient( self::TRANSIENT );

		if ( is_wp_error( $response ) ) {
			return array(
				'result' => self::UNREACHABLE,
				'detail' => 'This site could not call itself (' . $response->get_error_message() . '). WordPress\'s Site Health > "Loopback request" test usually shows the same problem.',
			);
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['authorization'] ) ) {
			return array(
				'result' => self::UNREACHABLE,
				'detail' => sprintf( 'The REST API answered with HTTP %d instead of the expected reply. A security plugin or firewall may be blocking the REST API.', $code ),
			);
		}
		return array(
			'result' => self::RECEIVED === $body['authorization'] ? self::RECEIVED : self::MISSING,
			'detail' => '',
		);
	}
}
