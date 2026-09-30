<?php
/**
 * Signs in REST requests that carry one of our access tokens, as the user
 * the connection posts as (limited by Auth\Sandbox). Only REST requests: the token never works in
 * wp-admin, admin-ajax, admin-post, on the front end, or for XML-RPC.
 *
 * "REST request" means WordPress itself has routed the request to the REST
 * API (REST_REQUEST is defined), as core does for application passwords.
 * A URL that merely looks like the REST API isn't enough: admin-ajax.php
 * with ?rest_route=... would otherwise sign the token in there, where none
 * of RouteGate's limits apply.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Auth;

use CPub\Connector\ActivityLog;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\PublisherUser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BearerAuth {

	/**
	 * Where the token may arrive. The standard Authorization header first; the
	 * X-CPub-Authorization copy is for hosts that strip the standard header
	 * (see the "Authorization header" readiness check).
	 */
	private const SOURCES = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_CPUB_AUTHORIZATION' );

	private static ?object $grant    = null;
	private static ?\WP_Error $error  = null;
	private static bool $attempted    = false;

	public static function register(): void {
		// After cookies (10) and application passwords (20).
		add_filter( 'determine_current_user', array( self::class, 'determine_current_user' ), 30 );
		add_filter( 'rest_authentication_errors', array( self::class, 'authentication_errors' ), 90 );
	}

	public static function is_rest_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST
			&& ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			&& ! wp_doing_ajax() && ! wp_doing_cron() && ! is_admin();
	}

	/** @param int|false $user_id */
	public static function determine_current_user( $user_id ) {
		if ( $user_id || ! self::is_rest_request() ) {
			return $user_id;
		}
		return self::authenticate() ?: $user_id;
	}

	/**
	 * Runs inside the REST server before any endpoint. If the current user was
	 * looked up before WordPress knew this was a REST request, the token is
	 * checked here instead.
	 *
	 * @param \WP_Error|true|null $result
	 */
	public static function authentication_errors( $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! self::$attempted && self::is_rest_request() && 0 === get_current_user_id() ) {
			$user_id = self::authenticate();
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}
		if ( self::$error ) {
			return self::$error;
		}
		return self::$grant ? true : $result;
	}

	/** @return int user ID, or 0 */
	private static function authenticate(): int {
		if ( self::$attempted ) {
			return self::$grant ? (int) self::$grant->user_id : 0;
		}
		$token = self::token_from_request();
		if ( null === $token ) {
			return 0;
		}
		self::$attempted = true;

		$grant = TokenStore::validate_access( $token );
		if ( ! $grant ) {
			self::$error = new \WP_Error( 'cpub_invalid_token', 'The access token is invalid, expired or revoked.', array( 'status' => 401 ) );
			return 0;
		}
		$user = get_userdata( (int) $grant->user_id );
		if ( ! $user ) {
			Grants::revoke( (int) $grant->id, 'user_not_allowed', 0 );
			self::$error = new \WP_Error( 'cpub_invalid_token', 'The user this connection posts as no longer exists. Reconnect from the agency side.', array( 'status' => 401 ) );
			return 0;
		}
		if ( ! PublisherUser::may_post_as( $user ) ) {
			// The dedicated account was given more than Author rights, or the person
			// posts appear under became an administrator or lost the right to write.
			$legacy = PublisherUser::is_dedicated( $user->ID );
			Grants::revoke( (int) $grant->id, $legacy ? 'user_elevated' : 'user_not_allowed', 0 );
			self::$error = new \WP_Error( 'cpub_invalid_token', $legacy ? 'The connection was cut off because its user had more than Author permissions.' : 'The connection was cut off because the person posts appear under is no longer an Author or Editor who can write posts.', array( 'status' => 401 ) );
			return 0;
		}
		self::$grant = $grant;
		Grants::touch( $grant );
		return $user->ID;
	}

	public static function token_from_request(): ?string {
		foreach ( self::SOURCES as $key ) {
			$value = isset( $_SERVER[ $key ] ) ? trim( (string) $_SERVER[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( preg_match( '/^Bearer\s+(' . preg_quote( TokenStore::ACCESS_PREFIX, '/' ) . '[A-Za-z0-9]+)$/', $value, $m ) ) {
				return $m[1];
			}
		}
		return null;
	}

	/** The connection behind this request, if it was signed in with our token. */
	public static function grant(): ?object {
		return self::$grant;
	}

	/** @return string[] */
	public static function scopes(): array {
		return self::$grant ? Grants::scopes( self::$grant ) : array();
	}

	public static function reset(): void {
		self::$grant     = null;
		self::$error     = null;
		self::$attempted = false;
	}
}
