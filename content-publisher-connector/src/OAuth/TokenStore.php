<?php
/**
 * Codes and tokens, stored as SHA-256 hashes only.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

use CPub\Connector\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TokenStore {

	public const CODE    = 'code';
	public const ACCESS  = 'access';
	public const REFRESH = 'refresh';

	/** Prefix on access tokens, so our tokens are recognisable (and other plugins' bearer tokens are left alone). */
	public const ACCESS_PREFIX = 'cpub_at_';

	public static function hash( string $raw ): string {
		return hash( 'sha256', $raw );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** How long a refresh token that was just used may be presented again (see RefreshTokenRepository). */
	public const REISSUE_GRACE = 120;

	public static function insert( string $kind, string $raw, int $grant_id, \DateTimeImmutable $expires, ?int $parent_id = null ): bool {
		global $wpdb;
		return (bool) $wpdb->insert(
			Installer::table( 'tokens' ),
			array(
				'grant_id'   => $grant_id,
				'parent_id'  => $parent_id,
				'kind'       => $kind,
				'token_hash' => self::hash( $raw ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires->getTimestamp() ),
				'created_at' => self::now(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/** Tokens issued in exchange for the refresh token with this row id. */
	public static function children( int $parent_id ): array {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE parent_id = %d", $parent_id ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Atomically retires an unused token by row id. False if it was used or retired meanwhile. */
	public static function retire_unused( int $id ): bool {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revoked_at = %s WHERE id = %d AND used_at IS NULL AND revoked_at IS NULL", self::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function revoke_id( int $id ): void {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL", self::now(), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function find( string $kind, string $raw ): ?object {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s AND kind = %s", self::hash( $raw ), $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * Marks a single-use code or refresh token as used. Atomic, so two requests
	 * racing with the same token can't both succeed.
	 */
	public static function claim( string $kind, string $raw ): bool {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		$now   = self::now();
		$rows  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE token_hash = %s AND kind = %s AND used_at IS NULL AND revoked_at IS NULL AND expires_at > %s", $now, self::hash( $raw ), $kind, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === $rows;
	}

	public static function revoke( string $kind, string $raw ): void {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revoked_at = %s WHERE token_hash = %s AND kind = %s AND revoked_at IS NULL", self::now(), self::hash( $raw ), $kind ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function revoke_grant( int $grant_id ): void {
		global $wpdb;
		$table = Installer::table( 'tokens' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET revoked_at = %s WHERE grant_id = %d AND revoked_at IS NULL", self::now(), $grant_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The connection behind a usable access token, or null.
	 *
	 * @return object|null grant row with token_expires_at added
	 */
	public static function validate_access( string $raw ): ?object {
		global $wpdb;
		if ( ! str_starts_with( $raw, self::ACCESS_PREFIX ) ) {
			return null;
		}
		$tokens = Installer::table( 'tokens' );
		$grants = Installer::table( 'grants' );
		$row    = $wpdb->get_row( // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT g.*, t.expires_at AS token_expires_at FROM {$tokens} t JOIN {$grants} g ON g.id = t.grant_id
				 WHERE t.token_hash = %s AND t.kind = %s AND t.revoked_at IS NULL AND t.expires_at > %s AND g.status = 'active'",
				self::hash( substr( $raw, strlen( self::ACCESS_PREFIX ) ) ),
				self::ACCESS,
				self::now()
			)
		); // phpcs:enable
		return $row ?: null;
	}

	/** Access token string as handed out: prefix + identifier. */
	public static function access_string( string $identifier ): string {
		return self::ACCESS_PREFIX . $identifier;
	}
}
