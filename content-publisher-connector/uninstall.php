<?php
/**
 * Runs when the plugin is deleted. Removes its tables, keys and settings.
 *
 * The "agency-publisher" user is kept: posts it created belong to it, and
 * deleting it would delete or reassign them. An administrator can delete it
 * under Users if they want to.
 *
 * @package CPub\Connector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	return;
}

require_once __DIR__ . '/src/Autoloader.php';
\CPub\Connector\Autoloader::register();

\CPub\Connector\Installer::drop_tables();
\CPub\Connector\OAuth\Keys::delete();
delete_option( \CPub\Connector\PublisherUser::OPTION );
delete_transient( 'cpub_connector_auth_probe' );
wp_clear_scheduled_hook( \CPub\Connector\Installer::DAILY_HOOK );
