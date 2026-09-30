<?php
/**
 * Plugin Name:       Content Publisher Connector
 * Description:       Lets this site give the agency's Content Publisher limited access (via OAuth 2) to create draft posts and upload media. Access can be revoked at any time.
 * Version:           0.5.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            Agency
 * License:           Proprietary
 * Text Domain:       content-publisher-connector
 *
 * @package CPub\Connector
 */

// Keep this file parseable by old PHP: the requirements check runs first.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPUB_CONNECTOR_VERSION', '0.5.0' );
define( 'CPUB_CONNECTOR_FILE', __FILE__ );
define( 'CPUB_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPUB_CONNECTOR_MIN_PHP', '8.2' );
define( 'CPUB_CONNECTOR_MIN_WP', '6.5' );

require_once CPUB_CONNECTOR_DIR . 'src/Requirements.php';

$cpub_connector_problems = \CPub\Connector\Requirements::problems();

if ( $cpub_connector_problems ) {
	\CPub\Connector\Requirements::show_notice( $cpub_connector_problems );
	return;
}

require_once CPUB_CONNECTOR_DIR . 'src/Autoloader.php';
\CPub\Connector\Autoloader::register();

register_activation_hook( __FILE__, array( \CPub\Connector\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CPub\Connector\Installer::class, 'deactivate' ) );

\CPub\Connector\Plugin::instance()->boot();
