<?php
/**
 * Encrypts secrets (client-site tokens) with CPUB_PUBLISHER_KEY.
 *
 * XChaCha20-Poly1305 (libsodium) with a random nonce per value. Each value is
 * bound to where it belongs (e.g. "site:3:refresh") as associated data, so a
 * ciphertext copied into another row or site fails to decrypt instead of
 * being used there.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Crypto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Vault {

	private const PREFIX = 'v1:';

	public static function encrypt( string $plain, string $context ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES );
		$cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, 'cpub:' . $context, $nonce, Key::bytes() );
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/** @throws \RuntimeException when the value was changed, moved, or the key differs. */
	public static function decrypt( string $sealed, string $context ): string {
		if ( ! str_starts_with( $sealed, self::PREFIX ) ) {
			throw new \RuntimeException( 'Unknown encrypted format.' );
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true );
		$len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
		if ( false === $raw || strlen( $raw ) <= $len ) {
			throw new \RuntimeException( 'Encrypted value is damaged.' );
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, $len ), 'cpub:' . $context, substr( $raw, 0, $len ), Key::bytes() );
		if ( false === $plain ) {
			throw new \RuntimeException( 'Could not decrypt: the encryption key changed, or the stored value was altered.' );
		}
		return $plain;
	}
}
