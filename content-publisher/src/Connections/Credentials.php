<?php
/**
 * A client site's OAuth tokens (the credentials table), always encrypted.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

use CPub\Publisher\Crypto\Vault;
use CPub\Publisher\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Credentials {

	public const CLIENT_ID = 'cpub-publisher';

	public static function save( int $site_id, string $access, string $refresh, int $expires_in, string $scopes = '' ): bool {
		global $wpdb;
		$table = Installer::table( 'credentials' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$data  = array(
			'site_id'           => $site_id,
			'client_id'         => self::CLIENT_ID,
			'access_token'      => Vault::encrypt( $access, "site:{$site_id}:access" ),
			'refresh_token'     => Vault::encrypt( $refresh, "site:{$site_id}:refresh" ),
			'access_expires_at' => gmdate( 'Y-m-d H:i:s', time() + max( 0, $expires_in ) ),
			'scopes'            => $scopes,
			'updated_at'        => $now,
		);
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) {
			return false !== $wpdb->update( $table, $data, array( 'site_id' => $site_id ) );
		}
		return false !== $wpdb->insert( $table, $data + array( 'created_at' => $now ) );
	}

	/**
	 * @return array{access:string, refresh:string, expires_at:int, scopes:string}|null
	 * @throws \RuntimeException if the stored values can't be decrypted
	 */
	public static function load( int $site_id ): ?array {
		global $wpdb;
		$table = Installer::table( 'credentials' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || ! $row->refresh_token ) {
			return null;
		}
		return array(
			'access'     => Vault::decrypt( (string) $row->access_token, "site:{$site_id}:access" ),
			'refresh'    => Vault::decrypt( (string) $row->refresh_token, "site:{$site_id}:refresh" ),
			'expires_at' => (int) strtotime( $row->access_expires_at . ' UTC' ),
			'scopes'     => (string) $row->scopes,
		);
	}

	/** The permissions the connection was granted (no decryption needed). */
	public static function scopes( int $site_id ): array {
		global $wpdb;
		$table = Installer::table( 'credentials' );
		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT scopes FROM {$table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( explode( ' ', $value ) ) );
	}

	/**
	 * Record the permissions as the Connector reports them. Its token replies
	 * don't list them, so the connection check is where we learn them.
	 */
	public static function set_scopes( int $site_id, array $scopes ): void {
		global $wpdb;
		$scopes = array_values( array_filter( $scopes, fn( $s ) => is_string( $s ) && preg_match( '/^[a-z]+:[a-z]+$/', $s ) ) );
		$wpdb->update( Installer::table( 'credentials' ), array( 'scopes' => implode( ' ', $scopes ) ), array( 'site_id' => $site_id ) );
	}

	public static function delete( int $site_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::table( 'credentials' ), array( 'site_id' => $site_id ) );
	}
}
