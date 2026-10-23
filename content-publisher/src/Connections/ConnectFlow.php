<?php
/**
 * Connecting a client site, through WordPress.com (Wpcom).
 *
 * start(): look the site up on WordPress.com (it must run Jetpack, connected),
 * then send the staff member's browser to WordPress.com's approval screen for
 * that one site, where someone who can post on it logs in and approves.
 * callback(): check the response belongs to the approval we started, exchange
 * the code for the site's token (with the app's secret), and check the token is
 * for that same site.
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

	/** Register exactly this as the WordPress.com app's redirect URL. */
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
		if ( ! Wpcom::configured() ) {
			return new \WP_Error( 'cpub_no_app', 'Add the agency\'s WordPress.com app to wp-config.php first: Content Publisher > Status shows how.' );
		}
		$url = Sites::normalize_url( $url );
		if ( ! $url ) {
			return new \WP_Error( 'cpub_bad_url', 'Enter the client site\'s address, starting with https://.' );
		}
		if ( untrailingslashit( Sites::normalize_url( home_url() ) ?? '' ) === $url ) {
			return new \WP_Error( 'cpub_self', 'That is this site. Enter the client\'s site address.' );
		}
		$found = Wpcom::lookup( $url );
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$site  = Sites::find_by_url( $url );
		$other = Sites::find_by_wpcom_id( $found['id'] );
		if ( $other && ( ! $site || (int) $other->id !== (int) $site->id ) ) {
			return new \WP_Error( 'cpub_duplicate', sprintf( 'That is the same site as %s, already in the list. Use that one.', $other->name ?: $other->url ) );
		}
		$id     = $site ? (int) $site->id : Sites::create( $url, $found['name'] ?: (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$update = array( 'wpcom_site_id' => $found['id'] );
		if ( $found['name'] ) {
			$update['name'] = $found['name'];
		}
		if ( ! $site || Sites::CONNECTED !== $site->status ) {
			$update['status'] = Sites::PENDING;
		}
		Sites::update( $id, $update );

		$state = self::b64url( random_bytes( 24 ) );
		set_transient( self::state_key( $state ), array( 'site_id' => $id, 'user_id' => $user_id, 'wpcom_site_id' => $found['id'] ), self::STATE_TTL );
		Events::log( 'connect_started', 'Sent to WordPress.com\'s approval screen.', $id );

		return add_query_arg(
			array_map(
				'rawurlencode',
				array(
					'client_id'     => Wpcom::client_id(),
					'redirect_uri'  => self::redirect_uri(),
					'response_type' => 'code',
					// Only this site: the token can't reach the approver's other sites.
					'blog'          => (string) $found['id'],
					'state'         => $state,
				)
			),
			Wpcom::AUTHORIZE
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

		if ( isset( $query['error'] ) ) {
			$denied = 'access_denied' === $query['error'];
			$msg    = $denied ? 'Access was denied on WordPress.com.' : 'WordPress.com refused the request: ' . sanitize_text_field( $query['error'] . ( isset( $query['error_description'] ) ? ' (' . $query['error_description'] . ')' : '' ) );
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

		$result = Http::post_form(
			Wpcom::TOKEN,
			array(
				'client_id'     => Wpcom::client_id(),
				'client_secret' => Wpcom::client_secret(),
				'redirect_uri'  => self::redirect_uri(),
				'code'          => $code,
				'grant_type'    => 'authorization_code',
			)
		);
		$token = is_wp_error( $result ) ? null : $result['data'];
		if ( is_wp_error( $result ) || 200 !== $result['status'] || empty( $token['access_token'] ) ) {
			$msg = 'Approved, but getting the access token from WordPress.com failed: ' . Http::describe( $result );
			Sites::update( (int) $site->id, array( 'last_error' => $msg ) );
			Events::log( 'connect_failed', $msg, (int) $site->id );
			return new \WP_Error( 'cpub_exchange', $msg );
		}
		// The approval screen lets the person pick another of their sites: only the one we asked for counts.
		if ( (int) ( $token['blog_id'] ?? 0 ) !== (int) $saved['wpcom_site_id'] ) {
			$picked = sanitize_text_field( (string) ( $token['blog_url'] ?? 'another site' ) );
			$msg    = sprintf( 'Access was approved for %1$s, not for %2$s, so it wasn\'t kept. Start again and choose %2$s on WordPress.com\'s approval screen.', $picked, $site->url );
			Sites::update( (int) $site->id, array( 'last_error' => $msg ) );
			Events::log( 'connect_failed', $msg, (int) $site->id );
			return new \WP_Error( 'cpub_wrong_site', $msg );
		}

		Credentials::save( (int) $site->id, (string) $token['access_token'], (string) ( $token['scope'] ?? '' ) );
		Sites::update(
			(int) $site->id,
			array(
				'status'       => Sites::CONNECTED,
				'connected_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_error'   => null,
			)
		);
		Events::log( 'connected', 'Connected through WordPress.com.', (int) $site->id );
		HealthCheck::check( Sites::get( (int) $site->id ) ); // who approved, and what they may do there
		return Sites::get( (int) $site->id );
	}
}
