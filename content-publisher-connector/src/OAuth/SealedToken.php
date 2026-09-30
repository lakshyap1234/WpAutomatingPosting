<?php
/**
 * Reads sealed (encrypted) authorization codes and refresh tokens.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

use CPub\Connector\Vendor\League\OAuth2\Server\CryptTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SealedToken {
	use CryptTrait;

	public function __construct() {
		$this->setEncryptionKey( Keys::encryption_key() );
	}

	/** @return array<string, mixed>|null the payload, or null if it isn't one of ours */
	public function payload( string $sealed ): ?array {
		if ( '' === $sealed ) {
			return null;
		}
		try {
			$data = json_decode( $this->decrypt( $sealed ), true );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $data ) ? $data : null;
	}
}
