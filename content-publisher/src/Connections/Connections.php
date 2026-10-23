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
	 * Forgets our token for the site. WordPress.com has no way for an app to
	 * revoke its own token, so the connection stays listed there (under the
	 * approver's Connected applications) until they remove it; with our copy
	 * deleted, nobody can use it.
	 *
	 * @return true|\WP_Error
	 */
	public static function disconnect( object $site ) {
		return SiteClient::with_lock(
			(int) $site->id,
			function () use ( $site ) {
				Credentials::delete( (int) $site->id );
				Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED, 'last_error' => null ) );
				Events::log( 'disconnected', 'Disconnected from our side: our access token was deleted.', (int) $site->id );
				return true;
			}
		);
	}

	/**
	 * Upgrading from the Content Publisher Connector: those connections can't be
	 * used through WordPress.com. Their tokens are deleted and each site asks to
	 * be reconnected (through Jetpack).
	 */
	public static function retire_connector_sites(): int {
		$n = 0;
		foreach ( Sites::all() as $site ) {
			if ( (int) ( $site->wpcom_site_id ?? 0 ) > 0 ) {
				continue;
			}
			Credentials::delete( (int) $site->id );
			if ( Sites::DISCONNECTED !== $site->status ) {
				Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED, 'last_error' => 'Connections now go through Jetpack and WordPress.com. Install Jetpack on this site, connect it, then click Reconnect.' ) );
				Events::log( 'disconnected', 'Retired the Connector connection: reconnect through Jetpack.', (int) $site->id );
				++$n;
			}
		}
		return $n;
	}

	/** @return true|\WP_Error */
	public static function remove( object $site ) {
		if ( Sites::CONNECTED === $site->status ) {
			return new \WP_Error( 'cpub_connected', 'Disconnect the site before removing it.' );
		}
		$done = SiteClient::with_lock(
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
