<?php
/**
 * Calls a connected client site's REST API with its access token, renewing
 * the token when needed.
 *
 * Renewal is the risky part: refresh tokens are single use, and the Connector
 * treats a second use as theft and cuts the connection. So only one renewal per
 * site may run at a time (a database lock), and whoever gets the lock second
 * re-reads the stored tokens first and uses the fresh ones instead of renewing
 * again.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConnectorClient {

	/** Renew this long before the access token expires. */
	public const RENEW_MARGIN  = 120;
	public const LOCK_WAIT     = 20;
	public const TOKEN_TIMEOUT = 30;
	/** Wait before repeating a renewal whose reply was lost (the Connector re-issues within 2 minutes). */
	public const RETRY_DELAY   = 2;
	/** Tests shorten the wait. */
	public static int $retry_delay = self::RETRY_DELAY;

	/**
	 * Runs $fn while holding the site's lock (renewal, disconnect and remove all
	 * take it, so none of them can interleave). Returns WP_Error if busy.
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
	 * @param array{query?:array, json?:mixed, body?:string, headers?:array, timeout?:int} $args
	 * @return array{status:int, data:mixed, headers:array}|\WP_Error
	 */
	public static function request( object $site, string $method, string $route, array $args = array() ) {
		if ( Sites::CONNECTED !== $site->status ) {
			return new \WP_Error( 'cpub_not_connected', sprintf( '%s is not connected.', $site->name ?: $site->url ) );
		}
		$token = self::access_token( $site );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$result = self::send( $site, $method, $route, $args, $token );
		if ( ! is_wp_error( $result ) && 401 === $result['status'] ) {
			// Revoked or expired early: renew once, then retry once.
			$token = self::renew( $site, $token );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = self::send( $site, $method, $route, $args, $token );
			if ( ! is_wp_error( $result ) && 401 === $result['status'] ) {
				// A fresh token refused: not a revocation (renewal worked). Most likely the
				// host strips the auth headers, or the site's agency user was deleted.
				Sites::update( (int) $site->id, array( 'last_error' => 'The site refuses our access token even though renewing it worked (' . Http::describe( $result ) . '). Check the Connector\'s status page on that site.' ) );
			}
		}
		return $result;
	}

	private static function send( object $site, string $method, string $route, array $args, string $token ) {
		$headers = array_merge(
			array(
				'Authorization'        => 'Bearer ' . $token,
				'X-CPub-Authorization' => 'Bearer ' . $token, // for hosts that strip Authorization
			),
			$args['headers'] ?? array()
		);
		$method = strtoupper( $method );
		$http   = $method;
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			// Some hosts refuse PUT/PATCH/DELETE outright (405); WordPress honours this override.
			$headers['X-HTTP-Method-Override'] = $method;
			$http                               = 'POST';
		}
		$request = array( 'headers' => $headers );
		if ( isset( $args['timeout'] ) ) {
			$request['timeout'] = (int) $args['timeout']; // uploads need longer than API calls
		}
		if ( array_key_exists( 'json', $args ) ) {
			$request['body']                   = wp_json_encode( $args['json'] );
			$request['headers']['Content-Type'] = 'application/json';
		} elseif ( isset( $args['body'] ) ) {
			$request['body'] = $args['body'];
		}
		return Http::request( $http, Http::rest_url( $site->url, $route, $args['query'] ?? array() ), $request );
	}

	/** @return string|\WP_Error */
	private static function access_token( object $site ) {
		try {
			$creds = Credentials::load( (int) $site->id );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'cpub_vault', 'The stored access for this site can\'t be read (' . $e->getMessage() . '). Reconnect the site.' );
		}
		if ( ! $creds ) {
			return new \WP_Error( 'cpub_not_connected', 'No stored access for this site. Reconnect it.' );
		}
		if ( $creds['expires_at'] - self::RENEW_MARGIN > time() ) {
			return $creds['access'];
		}
		return self::renew( $site, $creds['access'] );
	}

	/**
	 * Renews the access token, once, under a per-site lock.
	 *
	 * @param string $stale the access token the caller found unusable
	 * @return string|\WP_Error the new access token
	 */
	public static function renew( object $site, string $stale ) {
		return self::with_lock( (int) $site->id, fn() => self::renew_locked( $site, $stale ) );
	}

	private static function renew_locked( object $site, string $stale ) {
		try {
			$creds = Credentials::load( (int) $site->id ); // fresh: someone may have renewed while we waited
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'cpub_vault', 'The stored access for this site can\'t be read (' . $e->getMessage() . '). Reconnect the site.' );
		}
		if ( ! $creds ) {
			return new \WP_Error( 'cpub_not_connected', 'This site was disconnected.' );
		}
		if ( ! hash_equals( $creds['access'], $stale ) && $creds['expires_at'] - self::RENEW_MARGIN > time() ) {
			return $creds['access'];
		}

		$endpoints = Sites::endpoints( $site );
		$params    = array(
			'grant_type'    => 'refresh_token',
			'client_id'     => Credentials::CLIENT_ID,
			'refresh_token' => $creds['refresh'],
		);
		$result = self::post_token( $endpoints['token_endpoint'] ?? '', $params );
		if ( self::uncertain( $result ) ) {
			// We don't know whether the site rotated the token. Asking again soon with
			// the same token is safe: the Connector re-issues within its grace window.
			sleep( self::$retry_delay );
			$result = self::post_token( $endpoints['token_endpoint'] ?? '', $params );
		}
		if ( self::uncertain( $result ) ) {
			$msg = 'Couldn\'t renew access: ' . Http::describe( $result );
			Sites::update( (int) $site->id, array( 'last_error' => $msg ) );
			return new \WP_Error( 'cpub_renew_failed', $msg );
		}
		$data = $result['data'];
		if ( 200 === $result['status'] && ! empty( $data['access_token'] ) && ! empty( $data['refresh_token'] ) ) {
			// Store the new pair straight away: the old refresh token is now used up.
			$saved = Credentials::save( (int) $site->id, (string) $data['access_token'], (string) $data['refresh_token'], (int) ( $data['expires_in'] ?? 3600 ), (string) ( $data['scope'] ?? $creds['scopes'] ) );
			if ( ! $saved ) {
				$msg = 'Renewed access but couldn\'t store it (database error). The next renewal within 2 minutes will recover it.';
				Sites::update( (int) $site->id, array( 'last_error' => $msg ) );
				return new \WP_Error( 'cpub_store_failed', $msg );
			}
			return (string) $data['access_token'];
		}
		if ( self::revoked( $result ) ) {
			self::mark_disconnected( $site, 'The site no longer accepts our access (it was disconnected on the site, or the connection was revoked). ' . Http::describe( $result ) );
			return new \WP_Error( 'cpub_disconnected', 'The site disconnected us. Reconnect it to continue.' );
		}
		// Anything else (firewall page, plugin being updated, redirect, rate limit): keep the tokens.
		$msg = 'Couldn\'t renew access: ' . Http::describe( $result ) . '. The connection was kept; it will be retried.';
		Sites::update( (int) $site->id, array( 'last_error' => $msg ) );
		return new \WP_Error( 'cpub_renew_failed', $msg );
	}

	private static function post_token( string $endpoint, array $params ) {
		return Http::request( 'POST', $endpoint, array( 'body' => $params, 'timeout' => self::TOKEN_TIMEOUT, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ) ) );
	}

	/** No reply, or a server error: the site may or may not have rotated the token. */
	private static function uncertain( $result ): bool {
		return is_wp_error( $result ) || $result['status'] >= 500;
	}

	/** Only an OAuth "invalid_grant" (or an unknown client) means the site revoked us. */
	private static function revoked( array $result ): bool {
		$error = is_array( $result['data'] ) ? ( $result['data']['error'] ?? '' ) : '';
		return ( 400 === $result['status'] && 'invalid_grant' === $error ) || ( 401 === $result['status'] && 'invalid_client' === $error );
	}

	public static function mark_disconnected( object $site, string $reason ): void {
		Credentials::delete( (int) $site->id );
		Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED, 'last_error' => $reason ) );
		Events::log( 'disconnected_remote', $reason, (int) $site->id );
		$site->status = Sites::DISCONNECTED;
	}
}
