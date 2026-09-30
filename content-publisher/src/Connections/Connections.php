<?php
/**
 * Disconnecting and removing client sites from our side.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Connections {

	/**
	 * Revokes our access on the client's site, then forgets the tokens here.
	 * The tokens are forgotten even if the site can't be reached.
	 *
	 * @return true|\WP_Error WP_Error only to report that the site wasn't told
	 */
	public static function disconnect( object $site ) {
		$result = ConnectorClient::with_lock( (int) $site->id, fn() => self::disconnect_locked( $site ) );
		return $result;
	}

	private static function disconnect_locked( object $site ) {
		$warning = null;
		try {
			$creds = Credentials::load( (int) $site->id );
		} catch ( \RuntimeException $e ) {
			$creds   = null;
			$warning = new \WP_Error( 'cpub_revoke_failed', 'Disconnected here, but the stored access couldn\'t be read (the encryption key may have changed), so the client\'s site wasn\'t told. Ask its administrator to click Disconnect under Settings > Content Publisher.' );
		}
		$endpoint = Sites::endpoints( $site )['revocation_endpoint'] ?? '';
		if ( $creds && $endpoint ) {
			$result = Http::post_form( $endpoint, array( 'client_id' => Credentials::CLIENT_ID, 'token' => $creds['refresh'], 'token_type_hint' => 'refresh_token' ) );
			if ( is_wp_error( $result ) || 200 !== $result['status'] ) {
				$warning = new \WP_Error( 'cpub_revoke_failed', 'Disconnected here, but the client\'s site couldn\'t be told (' . Http::describe( $result ) . '). Our copy of the access is deleted, so we can\'t use it; the site will still list the connection until its administrator clicks Disconnect under Settings > Content Publisher.' );
			}
		}
		Credentials::delete( (int) $site->id );
		Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED, 'last_error' => $warning ? $warning->get_error_message() : null ) );
		Events::log( 'disconnected', 'Disconnected from our side.' . ( $warning ? ' The site could not be notified.' : '' ), (int) $site->id );
		return $warning ?? true;
	}

	/** @return true|\WP_Error */
	public static function remove( object $site ) {
		if ( Sites::CONNECTED === $site->status ) {
			return new \WP_Error( 'cpub_connected', 'Disconnect the site before removing it.' );
		}
		$done = ConnectorClient::with_lock(
			(int) $site->id,
			function () use ( $site ) {
				Credentials::delete( (int) $site->id );
				Sites::delete( (int) $site->id );
				return true;
			}
		);
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		Events::log( 'site_removed', sprintf( 'Removed %s (%s).', $site->name, $site->url ), (int) $site->id );
		return true;
	}
}
