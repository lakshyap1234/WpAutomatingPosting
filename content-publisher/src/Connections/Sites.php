<?php
/**
 * Client sites (the sites table).
 *
 * Status:
 *   pending       a connection was started but not approved yet
 *   connected     we hold working tokens (last_error set = the last check had a problem)
 *   disconnected  no tokens: disconnected from either side, or approval denied
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

use CPub\Publisher\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sites {

	public const PENDING      = 'pending';
	public const CONNECTED    = 'connected';
	public const DISCONNECTED = 'disconnected';

	/**
	 * Canonical form of a client address: https, lowercase host, no trailing slash,
	 * no query or fragment. Null if it isn't a usable https address.
	 */
	public static function normalize_url( string $url ): ?string {
		$url = trim( $url );
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$p = wp_parse_url( $url );
		if ( ! $p || empty( $p['host'] ) || 'https' !== strtolower( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return null;
		}
		$host = strtolower( $p['host'] );
		if ( ! preg_match( '/^[a-z0-9.-]+$/', $host ) || ! str_contains( $host, '.' ) ) {
			return null;
		}
		$port = isset( $p['port'] ) && 443 !== (int) $p['port'] ? ':' . (int) $p['port'] : '';
		$path = rtrim( $p['path'] ?? '', '/' );
		return 'https://' . $host . $port . $path;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = Installer::table( 'sites' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function find_by_url( string $url ): ?object {
		global $wpdb;
		$table = Installer::table( 'sites' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE url = %s", $url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/** @return object[] */
	public static function all( ?string $status = null ): array {
		global $wpdb;
		$table = Installer::table( 'sites' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name, id", $status ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name, id" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Column limits: strings from client sites are cut to fit, or the whole write would fail. */
	private const LIMITS = array( 'name' => 190, 'remote_user' => 190, 'connector_version' => 20, 'url' => 190, 'status' => 20 );

	private static function fit( array $fields ): array {
		foreach ( self::LIMITS as $col => $max ) {
			if ( isset( $fields[ $col ] ) && is_string( $fields[ $col ] ) ) {
				$fields[ $col ] = mb_substr( $fields[ $col ], 0, $max );
			}
		}
		return $fields;
	}

	public static function create( string $url, string $name ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert(
			Installer::table( 'sites' ),
			self::fit( array( 'url' => $url, 'name' => $name, 'status' => self::PENDING, 'created_at' => $now, 'updated_at' => $now ) )
		);
		if ( ! $ok ) {
			throw new \RuntimeException( 'Could not save the client site: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $fields ): void {
		global $wpdb;
		if ( isset( $fields['endpoints'] ) && is_array( $fields['endpoints'] ) ) {
			$fields['endpoints'] = wp_json_encode( $fields['endpoints'] );
		}
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$wpdb->update( Installer::table( 'sites' ), self::fit( $fields ), array( 'id' => $id ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( Installer::table( 'sites' ), array( 'id' => $id ) );
	}

	/** @return array{authorization_endpoint?:string, token_endpoint?:string, revocation_endpoint?:string} */
	public static function endpoints( object $site ): array {
		$e = json_decode( (string) $site->endpoints, true );
		return is_array( $e ) ? $e : array();
	}
}
