<?php
/**
 * The Disconnect button.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Admin;

use CPub\Connector\OAuth\Grants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConnectionActions {

	public const ACTION     = 'cpub_connector_disconnect';
	public const PUBLISHING = 'cpub_connector_publishing';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'disconnect' ) );
		add_action( 'admin_post_' . self::PUBLISHING, array( self::class, 'publishing' ) );
	}

	/** Allow or stop direct publishing for the current connection. */
	public static function publishing(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only administrators can change this.', 403 );
		}
		check_admin_referer( self::PUBLISHING );
		$grant = Grants::active();
		if ( $grant ) {
			Grants::set_can_publish( (int) $grant->id, ! empty( $_POST['allow'] ), get_current_user_id() );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => StatusPage::SLUG ), admin_url( 'options-general.php' ) ) );
		if ( apply_filters( 'cpub_connector_exit_after_redirect', true ) ) {
			exit;
		}
	}

	public static function disconnect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Only administrators can disconnect.', 403 );
		}
		check_admin_referer( self::ACTION );
		$count = Grants::revoke_all( 'disconnected', get_current_user_id() );
		wp_safe_redirect( add_query_arg( array( 'page' => StatusPage::SLUG, 'cpub_disconnected' => $count ), admin_url( 'options-general.php' ) ) );
		if ( apply_filters( 'cpub_connector_exit_after_redirect', true ) ) {
			exit;
		}
	}
}
