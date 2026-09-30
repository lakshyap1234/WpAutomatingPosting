<?php
/**
 * Plugin Name:       Content Publisher
 * Description:       Turns client content into reviewed WordPress posts and sends them as drafts to the client's site through the Content Publisher Connector.
 * Version:           0.6.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Agency
 * License:           Proprietary
 * Text Domain:       content-publisher
 *
 * @package CPub\Publisher
 */

// This file must stay parseable by old PHP versions: the requirements check
// runs before any PHP 8.2 code is loaded, so a host on old PHP gets a notice
// instead of a fatal error.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CPUB_PUBLISHER_VERSION', '0.6.0' );
define( 'CPUB_PUBLISHER_FILE', __FILE__ );
define( 'CPUB_PUBLISHER_DIR', plugin_dir_path( __FILE__ ) );
define( 'CPUB_PUBLISHER_MIN_PHP', '8.2' );
define( 'CPUB_PUBLISHER_MIN_WP', '6.9' );

require_once CPUB_PUBLISHER_DIR . 'src/Requirements.php';

$cpub_publisher_problems = \CPub\Publisher\Requirements::problems();

if ( $cpub_publisher_problems ) {
	\CPub\Publisher\Requirements::show_notice( $cpub_publisher_problems );
	return;
}

// Action Scheduler (job queue). It picks the newest copy if several plugins bundle it.
require_once CPUB_PUBLISHER_DIR . 'lib/action-scheduler/action-scheduler.php';

require_once CPUB_PUBLISHER_DIR . 'src/Autoloader.php';
\CPub\Publisher\Autoloader::register();

register_activation_hook( __FILE__, array( \CPub\Publisher\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \CPub\Publisher\Connections\HealthCheck::class, 'unschedule' ) );

\CPub\Publisher\Plugin::instance()->boot();
