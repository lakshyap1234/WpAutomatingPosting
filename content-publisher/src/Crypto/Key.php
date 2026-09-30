<?php
/**
 * The key used to encrypt stored access tokens. It lives in wp-config.php,
 * not the database, so a database leak alone doesn't expose client sites.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Key {

	public const CONSTANT = 'CPUB_PUBLISHER_KEY';

	public const OK      = 'ok';
	public const MISSING = 'missing';
	public const INVALID = 'invalid';

	public static function state( ?string $value = null ): string {
		if ( null === $value ) {
			if ( ! defined( self::CONSTANT ) ) {
				return self::MISSING;
			}
			$value = (string) constant( self::CONSTANT );
		}
		$raw = base64_decode( $value, true );
		return ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) ? self::OK : self::INVALID;
	}

	/** The raw 32-byte key. Throws if it isn't configured correctly. */
	public static function bytes(): string {
		if ( self::OK !== self::state() ) {
			throw new \RuntimeException( 'CPUB_PUBLISHER_KEY is missing or invalid. See Content Publisher > Status.' );
		}
		return base64_decode( (string) constant( self::CONSTANT ), true );
	}

	/** A fresh key, as the line to paste into wp-config.php. */
	public static function suggested_config_line(): string {
		return sprintf( "define( '%s', '%s' );", self::CONSTANT, base64_encode( sodium_crypto_secretbox_keygen() ) );
	}
}
