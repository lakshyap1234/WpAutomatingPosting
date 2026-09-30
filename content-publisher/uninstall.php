<?php
/**
 * Runs when the plugin is deleted (not when it's deactivated).
 * Removes everything the plugin created: tables, options, capabilities,
 * queued jobs and stored files.
 *
 * @package CPub\Publisher
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	return; // The plugin never ran, so there is nothing to remove.
}

global $wpdb;
if ( ! defined( 'CPUB_PUBLISHER_VERSION' ) ) {
	define( 'CPUB_PUBLISHER_VERSION', 'uninstall' ); // the main plugin file isn't loaded during uninstall
}

require_once __DIR__ . '/src/Autoloader.php';
\CPub\Publisher\Autoloader::register();

// Tell each client site to revoke our access before our copy is destroyed, so
// no client is left with a live connection nobody holds. Best effort, short timeouts.
if ( defined( 'CPUB_PUBLISHER_KEY' ) && function_exists( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt' ) ) {
	add_filter( 'http_request_timeout', static fn() => 5 );
	foreach ( \CPub\Publisher\Connections\Sites::all( \CPub\Publisher\Connections\Sites::CONNECTED ) as $cpub_site ) {
		\CPub\Publisher\Connections\Connections::disconnect( $cpub_site );
	}
}

\CPub\Publisher\Installer::drop_tables();
delete_option( \CPub\Publisher\Settings\AiSettings::OPTION ); // the encrypted AI key
\CPub\Publisher\Capabilities::revoke();

// Pending approvals (they hold PKCE verifiers) and one-off notices.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_cpub_' ) . '%', $wpdb->esc_like( '_transient_timeout_cpub_' ) . '%', $wpdb->esc_like( '_site_transient_cpub_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_cpub_' ) . '%' ) );

// Queued background jobs. Action Scheduler's own tables stay: other plugins may use them.
$cpub_as_actions = $wpdb->prefix . 'actionscheduler_actions';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cpub_as_actions ) ) === $cpub_as_actions ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM `{$cpub_as_actions}` WHERE hook LIKE %s", $wpdb->esc_like( 'cpub_' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Uploaded source files and images waiting to be sent.
$cpub_uploads = wp_upload_dir( null, false );
$cpub_dir     = trailingslashit( $cpub_uploads['basedir'] ) . 'cpub-publisher';
if ( is_dir( $cpub_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->rmdir( $cpub_dir, true );
	}
}
