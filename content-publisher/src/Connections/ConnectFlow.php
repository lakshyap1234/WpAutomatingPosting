<?php
/**
 * Connecting a client site: the OAuth 2 authorization code flow with PKCE,
 * from our side (we are the client).
 *
 * start():    discover the Connector, remember a one-off state + PKCE verifier
 *             for this staff member, and return the client's approval URL.
 * callback(): the client's admin approved (or denied); check the state, and
 *             exchange the code for tokens server to server.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConnectFlow {

	public const STATE_TTL = 30 * MINUTE_IN_SECONDS;

	/** Must match what the client's Connector was built with (AGENCY_URL + this path). */
	public static function redirect_uri(): string {
		return admin_url( 'admin.php?page=cpub-publisher&cpub_oauth=callback' );
	}

	private static function state_key( string $state ): string {
		return 'cpub_oauth_' . hash( 'sha256', $state );
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/** @return string|\WP_Error the URL to send the staff member's browser to */
	public static function start( string $url, int $user_id ) {
		$url = Sites::normalize_url( $url );
		if ( ! $url ) {
			return new \WP_Error( 'cpub_bad_url', 'Enter the client site\'s address, starting with https://.' );
		}
		if ( untrailingslashit( Sites::normalize_url( home_url() ) ?? '' ) === $url ) {
			return new \WP_Error( 'cpub_self', 'That is this site. Enter the client\'s site address.' );
		}
		$meta = Discovery::discover( $url );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$site = Sites::find_by_url( $url );
		$id   = $site ? (int) $site->id : Sites::create( $url, $meta['site_name'] ?: (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$update = array(
			'endpoints'         => array_intersect_key( $meta, array_flip( array( 'issuer', 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint' ) ) ),
			'connector_version' => $meta['connector_version'],
		);
		if ( $meta['site_name'] ) {
			$update['name'] = $meta['site_name'];
		}
		if ( ! $site || Sites::CONNECTED !== $site->status ) {
			$update['status'] = Sites::PENDING;
		}
		Sites::update( $id, $update );

		$verifier = self::b64url( random_bytes( 48 ) );
		$state    = self::b64url( random_bytes( 24 ) );
		set_transient( self::state_key( $state ), array( 'site_id' => $id, 'verifier' => $verifier, 'user_id' => $user_id, 'issuer' => $meta['issuer'] ), self::STATE_TTL );
		Events::log( 'connect_started', 'Sent to the site\'s approval screen.', $id );

		return add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'response_type'         => 'code',
					'client_id'             => Credentials::CLIENT_ID,
					'redirect_uri'          => self::redirect_uri(),
					'scope'                 => implode( ' ', array_merge( Discovery::REQUIRED_SCOPES, array_intersect( Discovery::OPTIONAL_SCOPES, $meta['scopes_supported'] ?? array() ) ) ),
					'state'                 => $state,
					'code_challenge'        => self::b64url( hash( 'sha256', $verifier, true ) ),
					'code_challenge_method' => 'S256',
				)
			),
			$meta['authorization_endpoint']
		);
	}

	/**
	 * @param array $query the callback's query parameters (unslashed)
	 * @return object|\WP_Error the connected site
	 */
	public static function callback( array $query, int $user_id ) {
		$state = (string) ( $query['state'] ?? '' );
		$saved = '' !== $state ? get_transient( self::state_key( $state ) ) : false;
		if ( ! is_array( $saved ) ) {
			return new \WP_Error( 'cpub_state', 'This approval response is unknown or expired (approvals must be finished within 30 minutes). Start the connection again.' );
		}
		delete_transient( self::state_key( $state ) ); // single use
		if ( (int) $saved['user_id'] !== $user_id ) {
			return new \WP_Error( 'cpub_state', 'This approval was started by a different staff member. Start the connection again yourself.' );
		}
		$site = Sites::get( (int) $saved['site_id'] );
		if ( ! $site ) {
			return new \WP_Error( 'cpub_state', 'That client site was removed while waiting for approval.' );
		}

		// RFC 9207: the response must come from the site we sent the staff member to.
		// Otherwise a malicious site could relay us another site's code (mix-up attack).
		if ( ! hash_equals( (string) $saved['issuer'], (string) ( $query['iss'] ?? '' ) ) ) {
			Events::log( 'connect_failed', 'Approval response came from an unexpected site (' . sanitize_text_field( (string) ( $query['iss'] ?? 'unnamed' ) ) . '). Ignored.', (int) $site->id );
			return new \WP_Error( 'cpub_issuer', 'This approval response didn\'t come from the site you were connecting (' . $site->url . '), so it was ignored. Start the connection again.' );
		}

		if ( isset( $query['error'] ) ) {
			$denied = 'access_denied' === $query['error'];
			$msg    = $denied ? 'The site\'s administrator denied access.' : 'The site refused the request: ' . sanitize_text_field( $query['error'] . ( isset( $query['hint'] ) ? ' (' . $query['hint'] . ')' : '' ) );
			if ( Sites::CONNECTED !== $site->status ) {
				Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED, 'last_error' => $msg ) );
			}
			Events::log( $denied ? 'connect_denied' : 'connect_failed', $msg, (int) $site->id );
			return new \WP_Error( 'cpub_denied', $msg );
		}
		$code = (string) ( $query['code'] ?? '' );
		if ( '' === $code ) {
			return new \WP_Error( 'cpub_state', 'The approval response had no code. Start the connection again.' );
		}

		$endpoints = Sites::endpoints( $site );
		$result    = Http::post_form(
			$endpoints['token_endpoint'] ?? '',
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => Credentials::CLIENT_ID,
				'redirect_uri'  => self::redirect_uri(),
				'code'          => $code,
				'code_verifier' => $saved['verifier'],
			)
		);
		$tokens = is_wp_error( $result ) ? null : $result['data'];
		if ( is_wp_error( $result ) || 200 !== $result['status'] || empty( $tokens['access_token'] ) || empty( $tokens['refresh_token'] ) ) {
			$msg = 'Approved, but getting the access tokens failed: ' . Http::describe( $result );
			Sites::update( (int) $site->id, array( 'last_error' => $msg ) );
			Events::log( 'connect_failed', $msg, (int) $site->id );
			return new \WP_Error( 'cpub_exchange', $msg );
		}

		Credentials::save( (int) $site->id, (string) $tokens['access_token'], (string) $tokens['refresh_token'], (int) ( $tokens['expires_in'] ?? 3600 ), (string) ( $tokens['scope'] ?? implode( ' ', Discovery::REQUIRED_SCOPES ) ) );
		Sites::update(
			(int) $site->id,
			array(
				'status'       => Sites::CONNECTED,
				'connected_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_error'   => null,
			)
		);
		Events::log( 'connected', 'Connected. The site\'s administrator approved access.', (int) $site->id );
		HealthCheck::check( Sites::get( (int) $site->id ) ); // fills in the posting user and permissions
		return Sites::get( (int) $site->id );
	}
}
