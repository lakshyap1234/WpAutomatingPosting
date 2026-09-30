<?php
/**
 * Outbound HTTP to client sites. Always WordPress's "safe" functions, which
 * refuse private, loopback and cloud-metadata addresses and check redirects.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Http {

	public const TIMEOUT = 20;

	/**
	 * @return array{status:int, data:mixed, headers:array}|\WP_Error
	 */
	public static function request( string $method, string $url, array $args = array() ) {
		$args = array_merge(
			array(
				'method'      => $method,
				'timeout'     => self::TIMEOUT,
				'redirection' => 0, // a client site redirecting an API call would be a misconfiguration; don't follow
				'user-agent'  => 'ContentPublisher/' . CPUB_PUBLISHER_VERSION . '; ' . home_url( '/' ),
			),
			$args
		);
		$args['headers'] = array_merge( array( 'Accept' => 'application/json' ), $args['headers'] ?? array() );
		$response        = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'data'    => null === $data && '' !== $body ? $body : $data,
			'headers' => wp_remote_retrieve_headers( $response )->getAll(),
		);
	}

	public static function post_form( string $url, array $params ) {
		return self::request( 'POST', $url, array( 'body' => $params, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ) );
	}

	/** A REST route on a client site, in the ?rest_route= form that works with every permalink setting. */
	public static function rest_url( string $site_url, string $route, array $query = array() ): string {
		return add_query_arg( array_map( 'rawurlencode', array( 'rest_route' => $route ) + $query ), $site_url . '/' );
	}

	/** A short, readable description of a failed response. */
	public static function describe( $result ): string {
		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		$data = $result['data'];
		$msg  = is_array( $data ) ? ( $data['message'] ?? $data['error_description'] ?? $data['error'] ?? '' ) : '';
		return trim( 'HTTP ' . $result['status'] . ( $msg ? ': ' . wp_strip_all_tags( (string) $msg ) : '' ) );
	}
}
