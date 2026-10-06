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
}
