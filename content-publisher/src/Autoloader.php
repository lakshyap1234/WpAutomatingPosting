<?php
/**
 * PSR-4 autoloader for our own classes, plus the prefixed third-party libraries.
 * We don't ship Composer's autoloader so that another plugin's copy can't clash with ours.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	private const PREFIX = 'CPub\\Publisher\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );

		$vendor = dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
		if ( is_readable( $vendor ) ) {
			require_once $vendor;
		}
	}

	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) || str_starts_with( $class, self::PREFIX . 'Vendor\\' ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class, strlen( self::PREFIX ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
