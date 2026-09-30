<?php
/**
 * Finds a client site's Connector and its OAuth endpoints.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Discovery {

	public const REQUIRED_SCOPES = array( 'posts:write', 'media:write', 'terms:read', 'account:read' );

	/** Asked for when the Connector offers them (0.5.0+): creating tags. */
	public const OPTIONAL_SCOPES = array( 'terms:write' );

	/** Whether $url is on the same origin as $site_url and under its path. */
	public static function within( string $url, string $site_url ): bool {
		$u = wp_parse_url( $url );
		$s = wp_parse_url( $site_url );
		if ( ! $u || ! $s || 'https' !== ( $u['scheme'] ?? '' ) || isset( $u['user'] ) || isset( $u['pass'] ) ) {
			return false;
		}
		if ( strtolower( $u['host'] ?? '' ) !== strtolower( $s['host'] ?? '' ) || (int) ( $u['port'] ?? 443 ) !== (int) ( $s['port'] ?? 443 ) ) {
			return false;
		}
		$base = rtrim( $s['path'] ?? '', '/' ) . '/';
		return str_starts_with( ( $u['path'] ?? '/' ) ?: '/', $base );
	}

	/**
	 * @return array{issuer:string, authorization_endpoint:string, token_endpoint:string, revocation_endpoint:string, connector_version:string, site_name:string}|\WP_Error
	 */
	public static function discover( string $site_url ) {
		$result = Http::request( 'GET', Http::rest_url( $site_url, '/cpub-connector/v1/oauth/metadata' ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'cpub_unreachable', sprintf( 'Couldn\'t reach %s: %s', $site_url, $result->get_error_message() ) );
		}
		$data = $result['data'];
		if ( in_array( $result['status'], array( 301, 302, 307, 308 ), true ) ) {
			$to = (string) ( $result['headers']['location'] ?? '' );
			$to = $to ? ( Sites::normalize_url( preg_replace( '#[/?].*$#', '', preg_replace( '#^https?://#', '', $to ) ) ) ?? $to ) : 'another address';
			return new \WP_Error( 'cpub_redirect', sprintf( '%s redirects to %s. Enter that address instead.', $site_url, $to ) );
		}
		if ( 200 !== $result['status'] || ! is_array( $data ) || empty( $data['authorization_endpoint'] ) ) {
			$hint = 404 === $result['status'] ? ' The Content Publisher Connector plugin isn\'t installed or active there.' : '';
			return new \WP_Error( 'cpub_no_connector', sprintf( 'No Content Publisher Connector found at %s (%s).%s', $site_url, Http::describe( $result ), $hint ) );
		}

		foreach ( array( 'issuer', 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint' ) as $key ) {
			$endpoint = (string) ( $data[ $key ] ?? '' );
			// Everything must live on the site itself (same scheme, host and port, under
			// its address): we send its codes and tokens there.
			if ( ! self::within( $endpoint, $site_url ) ) {
				return new \WP_Error( 'cpub_bad_metadata', sprintf( 'The Connector at %s reported an unexpected %s (%s). Connection refused for safety.', $site_url, $key, $endpoint ?: 'none' ) );
			}
		}
		if ( empty( $data['authorization_response_iss_parameter_supported'] ) ) {
			return new \WP_Error( 'cpub_bad_metadata', 'The Connector on that site is too old (it doesn\'t identify itself in approval responses). Update it to 0.3.0 or later.' );
		}
		if ( ! in_array( 'S256', (array) ( $data['code_challenge_methods_supported'] ?? array() ), true ) ) {
			return new \WP_Error( 'cpub_bad_metadata', 'The Connector on that site doesn\'t support PKCE S256. Update it.' );
		}
		$missing = array_diff( self::REQUIRED_SCOPES, (array) ( $data['scopes_supported'] ?? array() ) );
		if ( $missing ) {
			return new \WP_Error( 'cpub_bad_metadata', 'The Connector on that site is too old (missing: ' . implode( ', ', $missing ) . '). Update it.' );
		}
		return array(
			'issuer'                 => (string) $data['issuer'],
			'authorization_endpoint' => (string) $data['authorization_endpoint'],
			'token_endpoint'         => (string) $data['token_endpoint'],
			'revocation_endpoint'    => (string) $data['revocation_endpoint'],
			'connector_version'      => (string) ( $data['connector_version'] ?? '' ),
			'site_name'              => wp_strip_all_tags( (string) ( $data['site_name'] ?? '' ) ),
			'scopes_supported'       => array_values( array_filter( (array) ( $data['scopes_supported'] ?? array() ), 'is_string' ) ),
		);
	}
}
