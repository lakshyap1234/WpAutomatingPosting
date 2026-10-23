<?php
/**
 * Shared PHPUnit bootstrap for both plugins (see TESTING.md).
 *
 * Needs the WordPress test library. wp-env's tests container provides it and sets
 * WP_TESTS_DIR. Elsewhere set WP_TESTS_DIR, or put WP_TESTS_DIR=... in .wp-tests.env
 * at the repo root.
 */

$cpub_repo = dirname( __DIR__ );
if ( ! is_readable( $cpub_repo . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Run composer install at the repo root first (it installs PHPUnit).\n" );
	exit( 1 );
}
require $cpub_repo . '/vendor/autoload.php';

function cpub_tests_boot( callable $load_plugin ): void {
	$repo      = dirname( __DIR__ );
	$tests_dir = getenv( 'WP_TESTS_DIR' );
	if ( ! $tests_dir && is_readable( $repo . '/.wp-tests.env' ) ) {
		$tests_dir = parse_ini_file( $repo . '/.wp-tests.env' )['WP_TESTS_DIR'] ?? '';
	}
	if ( ! $tests_dir || ! is_file( $tests_dir . '/includes/functions.php' ) ) {
		fwrite( STDERR, "WordPress test library not found. Use wp-env (see TESTING.md), or set WP_TESTS_DIR.\n" );
		exit( 1 );
	}
	if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $repo . '/vendor/yoast/phpunit-polyfills' );
	}
	require_once $tests_dir . '/includes/functions.php';
	tests_add_filter( 'muplugins_loaded', $load_plugin );
	require $tests_dir . '/includes/bootstrap.php';

	// The tests expect the test library's default address, http://example.org. wp-env
	// defines WP_HOME and WP_SITEURL (http://localhost:8889) instead: those constants
	// override the home and siteurl options, so a test couldn't change the site address,
	// and localhost would count as this site's own host (wp_http_validate_url() allows
	// it). Before the first test, so WP_UnitTestCase's saved hooks are these.
	remove_filter( 'option_home', '_config_wp_home' );
	remove_filter( 'option_siteurl', '_config_wp_siteurl' );
	update_option( 'home', 'http://example.org' );
	update_option( 'siteurl', 'http://example.org' );
}
