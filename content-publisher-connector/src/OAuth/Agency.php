<?php
/**
 * The trusted agency (the OAuth client), from config/agency.php.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agency {

	private static ?array $config = null;

	/**
	 * @return array{client_id:string, name:string, url:string, logo:string, redirect_uris:string[]}
	 */
	public static function config(): array {
		if ( null === self::$config ) {
			$config = require dirname( __DIR__, 2 ) . '/config/agency.php';
			/**
			 * Filters the trusted agency. For tests and development only.
			 *
			 * @param array $config
			 */
			$config       = apply_filters( 'cpub_connector_agency', $config );
			self::$config = array(
				'client_id'     => (string) ( $config['client_id'] ?? '' ),
				'name'          => (string) ( $config['name'] ?? 'Agency' ),
				'url'           => (string) ( $config['url'] ?? '' ),
				'logo'          => (string) ( $config['logo'] ?? '' ),
				'redirect_uris' => array_values( array_filter( (array) ( $config['redirect_uris'] ?? array() ), array( self::class, 'acceptable_redirect' ) ) ),
			);
		}
		return self::$config;
	}

	public static function reset(): void {
		self::$config = null;
	}

	public static function client_id(): string {
		return self::config()['client_id'];
	}

	public static function name(): string {
		return self::config()['name'];
	}

	/** HTTPS anywhere, or plain HTTP only to this computer (a test tool, RFC 8252). */
	public static function acceptable_redirect( $uri ): bool {
		$parts = is_string( $uri ) ? wp_parse_url( $uri ) : false;
		if ( ! $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		return 'https' === $parts['scheme'] || ( 'http' === $parts['scheme'] && self::is_loopback_host( $parts['host'] ) );
	}

	public static function is_loopback( string $uri ): bool {
		$host = (string) wp_parse_url( $uri, PHP_URL_HOST );
		return self::is_loopback_host( $host );
	}

	private static function is_loopback_host( string $host ): bool {
		return in_array( $host, array( '127.0.0.1', '[::1]' ), true );
	}

	public static function logo_url(): string {
		$logo = self::config()['logo'];
		if ( '' === $logo ) {
			return '';
		}
		if ( str_starts_with( $logo, 'https://' ) ) {
			return $logo;
		}
		$file = dirname( __DIR__, 2 ) . '/assets/' . basename( $logo );
		return is_readable( $file ) ? plugins_url( 'assets/' . basename( $logo ), CPUB_CONNECTOR_FILE ) : '';
	}
}
