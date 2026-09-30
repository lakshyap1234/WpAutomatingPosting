<?php
/**
 * Which connection the current OAuth request belongs to.
 *
 * The OAuth library calls our repositories one method at a time and doesn't
 * pass the connection along, so the first call that identifies it (checking
 * an authorization code or refresh token, or approving on the consent screen)
 * records it here for the calls that follow within the same request.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GrantContext {

	private static ?int $grant_id     = null;
	private static ?string $code_id   = null;
	private static ?int $parent_id    = null;

	public static function set( int $grant_id ): void {
		self::$grant_id = $grant_id;
	}

	public static function get(): int {
		if ( null === self::$grant_id ) {
			throw new \LogicException( 'No connection in context for this OAuth request.' );
		}
		return self::$grant_id;
	}

	public static function has(): bool {
		return null !== self::$grant_id;
	}

	/** An authorization code that passed the first checks and must be claimed before tokens are issued. */
	public static function set_pending_code( string $code_id ): void {
		self::$code_id = $code_id;
	}

	public static function take_pending_code(): ?string {
		$code          = self::$code_id;
		self::$code_id = null;
		return $code;
	}

	/** Row id of the refresh token being exchanged, recorded as the parent of the new tokens. */
	public static function set_parent( int $row_id ): void {
		self::$parent_id = $row_id;
	}

	public static function parent(): ?int {
		return self::$parent_id;
	}

	public static function clear(): void {
		self::$grant_id  = null;
		self::$code_id   = null;
		self::$parent_id = null;
	}
}
