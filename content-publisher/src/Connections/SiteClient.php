<?php
/**
 * Calls a connected client site through WordPress.com, with its token (Wpcom).
 *
 * Routes are the site's own: '/wp/v2/posts', or '/rest/v1.1/media/new' for
 * WordPress.com's own endpoints (see Wpcom::url). The token doesn't expire, so
 * there is nothing to renew. A 401 saying the token is invalid means it was
 * revoked (on WordPress.com, or Jetpack was disconnected): the site is marked
 * disconnected.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteClient {

	public const LOCK_WAIT = 20;

	/** WordPress.com's error codes for a token it no longer accepts. */
	private const REVOKED = array( 'invalid_token', 'authorization_required', 'unauthorized_token' );

	/**
	 * Runs $fn while holding the site's lock (disconnect and remove take it, so
	 * they can't interleave). Returns WP_Error if busy.
	 */
	public static function with_lock( int $site_id, callable $fn ) {
		global $wpdb;
		$lock = 'cpub_refresh_' . $site_id . '_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) ) ) {
			return new \WP_Error( 'cpub_busy', 'Another process is working on this site\'s connection. Try again in a moment.' );
		}
		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * @param array{query?:array, json?:mixed, body?:string, multipart?:array, headers?:array, timeout?:int} $args
	 *        multipart: a list of form fields, each array{name:string, value:string, filename?:string, type?:string}
	 * @return array{status:int, data:mixed, headers:array}|\WP_Error
	 */
	public static function request( object $site, string $method, string $route, array $args = array() ) {
		if ( Sites::CONNECTED !== $site->status ) {
			return new \WP_Error( 'cpub_not_connected', sprintf( '%s is not connected.', $site->name ?: $site->url ) );
		}
		$token = self::token( $site );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array_merge( array( 'Authorization' => 'Bearer ' . $token ), $args['headers'] ?? array() );
		$request = array( 'headers' => $headers );
		if ( isset( $args['timeout'] ) ) {
			$request['timeout'] = (int) $args['timeout']; // uploads need longer than API calls
		}
		if ( array_key_exists( 'json', $args ) ) {
			$request['body']                    = wp_json_encode( $args['json'] );
			$request['headers']['Content-Type'] = 'application/json';
		} elseif ( isset( $args['multipart'] ) ) {
			[ $request['body'], $request['headers']['Content-Type'] ] = self::multipart( $args['multipart'] );
		} elseif ( isset( $args['body'] ) ) {
			$request['body'] = $args['body'];
		}
		$result = Http::request( strtoupper( $method ), Wpcom::url( (int) $site->wpcom_site_id, $route, $args['query'] ?? array() ), $request );
		if ( ! is_wp_error( $result ) && 401 === $result['status'] && self::revoked( $result ) ) {
			self::mark_disconnected( $site, 'WordPress.com no longer accepts our access to this site (it was revoked there, or Jetpack was disconnected). ' . Http::describe( $result ) );
			return new \WP_Error( 'cpub_disconnected', 'WordPress.com no longer accepts our access to this site. Reconnect it to continue.' );
		}
		return $result;
	}

	/** @return string|\WP_Error */
	private static function token( object $site ) {
		if ( (int) $site->wpcom_site_id <= 0 ) {
			return new \WP_Error( 'cpub_not_connected', 'This site isn\'t connected through WordPress.com. Reconnect it.' );
		}
		try {
			$creds = Credentials::load( (int) $site->id );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'cpub_vault', 'The stored access for this site can\'t be read (' . $e->getMessage() . '). Reconnect the site.' );
		}
		return $creds ? $creds['access'] : new \WP_Error( 'cpub_not_connected', 'No stored access for this site. Reconnect it.' );
	}

	private static function revoked( array $result ): bool {
		$data = is_array( $result['data'] ) ? $result['data'] : array();
		return in_array( (string) ( $data['error'] ?? $data['code'] ?? '' ), self::REVOKED, true );
	}

	/**
	 * @param array<int, array{name:string, value:string, filename?:string, type?:string}> $parts
	 * @return array{0:string, 1:string} the body and its Content-Type
	 */
	private static function multipart( array $parts ): array {
		$boundary = 'cpub-' . wp_generate_password( 24, false );
		$body     = '';
		foreach ( $parts as $part ) {
			$disposition = 'form-data; name="' . str_replace( '"', '', $part['name'] ) . '"';
			if ( isset( $part['filename'] ) ) {
				$disposition .= '; filename="' . str_replace( array( '"', "\r", "\n" ), '', $part['filename'] ) . '"';
			}
			$body .= "--{$boundary}\r\nContent-Disposition: {$disposition}\r\n";
			if ( isset( $part['type'] ) ) {
				$body .= 'Content-Type: ' . $part['type'] . "\r\n";
			}
			$body .= "\r\n" . $part['value'] . "\r\n";
		}
		return array( $body . "--{$boundary}--\r\n", 'multipart/form-data; boundary=' . $boundary );
	}

	public static function mark_disconnected( object $site, string $reason ): void {
		Credentials::delete( (int) $site->id );
		Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED, 'last_error' => $reason ) );
		Events::log( 'disconnected_remote', $reason, (int) $site->id );
		$site->status = Sites::DISCONNECTED;
	}
}
