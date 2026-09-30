<?php
/**
 * Environment requirements, checked before any PHP 8.2 code is loaded.
 * Keep this file free of PHP 7.4+ syntax.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Requirements {

	/**
	 * @return string[] Human-readable problems; empty when the plugin can run.
	 */
	public static function problems() {
		global $wp_version;
		$problems = array();

		if ( version_compare( PHP_VERSION, CPUB_CONNECTOR_MIN_PHP, '<' ) ) {
			$problems[] = sprintf( 'PHP %s or newer is required (this site runs %s).', CPUB_CONNECTOR_MIN_PHP, PHP_VERSION );
		}
		if ( version_compare( $wp_version, CPUB_CONNECTOR_MIN_WP, '<' ) ) {
			$problems[] = sprintf( 'WordPress %s or newer is required (this site runs %s).', CPUB_CONNECTOR_MIN_WP, $wp_version );
		}
		if ( ! extension_loaded( 'openssl' ) ) {
			$problems[] = 'The PHP openssl extension is required to sign access tokens.';
		}
		return $problems;
	}

	public static function show_notice( array $problems ) {
		add_action(
			'admin_notices',
			function () use ( $problems ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				echo '<div class="notice notice-error"><p><strong>Content Publisher Connector is not running.</strong></p><ul style="list-style:disc;padding-left:2em">';
				foreach ( $problems as $p ) {
					echo '<li>' . esc_html( $p ) . '</li>';
				}
				echo '</ul></div>';
			}
		);
	}
}
