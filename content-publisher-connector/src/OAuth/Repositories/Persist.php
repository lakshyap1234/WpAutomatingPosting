<?php
/**
 * Inserts a token row, turning a duplicate into the library's retry signal.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Repositories;

use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Vendor\League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Persist {

	public static function insert( string $kind, string $identifier, int $grant_id, \DateTimeImmutable $expires ): void {
		global $wpdb;
		if ( TokenStore::insert( $kind, $identifier, $grant_id, $expires, \CPub\Connector\OAuth\GrantContext::parent() ) ) {
			return;
		}
		if ( str_contains( (string) $wpdb->last_error, 'Duplicate' ) ) {
			throw UniqueTokenIdentifierConstraintViolationException::create();
		}
		throw new \RuntimeException( 'Could not store the token: ' . $wpdb->last_error );
	}
}
