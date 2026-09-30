<?php
/**
 * Server secrets, created once per site on activation.
 *
 * - Encryption key: seals authorization codes and refresh tokens (defuse/php-encryption).
 * - Signing key: required by the OAuth library's constructor. Our access tokens are
 *   opaque random strings checked against the database, so it signs nothing today.
 *
 * Both live in wp_options (not autoloaded). Someone with a copy of the database
 * still can't call the site: tokens are only valid if their hash is in the
 * tokens table, and only hashes are stored.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

use CPub\Connector\Vendor\Defuse\Crypto\Key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Keys {

	public const ENCRYPTION_OPTION = 'cpub_connector_encryption_key';
	public const SIGNING_OPTION    = 'cpub_connector_signing_key';

	public static function ensure(): void {
		if ( ! get_option( self::ENCRYPTION_OPTION ) ) {
			update_option( self::ENCRYPTION_OPTION, Key::createNewRandomKey()->saveToAsciiSafeString(), false );
		}
		if ( ! get_option( self::SIGNING_OPTION ) ) {
			$pem = self::new_signing_key();
			if ( $pem ) {
				update_option( self::SIGNING_OPTION, $pem, false );
			}
		}
	}

	public static function encryption_key(): Key {
		self::ensure();
		return Key::loadFromAsciiSafeString( (string) get_option( self::ENCRYPTION_OPTION ) );
	}

	public static function signing_key(): string {
		self::ensure();
		$pem = (string) get_option( self::SIGNING_OPTION );
		if ( '' === $pem ) {
			throw new \RuntimeException( 'Could not create a signing key: this server\'s OpenSSL cannot generate keys.' );
		}
		return $pem;
	}

	public static function ready(): bool {
		return (bool) get_option( self::ENCRYPTION_OPTION ) && (bool) get_option( self::SIGNING_OPTION );
	}

	public static function delete(): void {
		delete_option( self::ENCRYPTION_OPTION );
		delete_option( self::SIGNING_OPTION );
	}

	/** EC P-256: quick to generate and accepted by the library. */
	private static function new_signing_key(): ?string {
		$key = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $key || ! openssl_pkey_export( $key, $pem ) ) {
			return null;
		}
		return $pem;
	}
}
