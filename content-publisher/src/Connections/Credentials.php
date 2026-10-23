<?php
/**
 * The access token for a client site (from WordPress.com), encrypted with
 * CPUB_PUBLISHER_KEY. It doesn't expire, so there is no refresh token.
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

	public static function save( int $site_id, string $access, string $scopes = '' ): bool {
		global $wpdb;
		$table = Installer::table( 'credentials' );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$data  = array(
			'site_id'      => $site_id,
			'client_id'    => mb_substr( Wpcom::client_id(), 0, 100 ),
			'access_token' => Vault::encrypt( $access, "site:{$site_id}:access" ),
			'scopes'       => mb_substr( $scopes, 0, 255 ),
			'updated_at'   => $now,
		);
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) {
			return false !== $wpdb->update( $table, $data, array( 'site_id' => $site_id ) );
		}
		return false !== $wpdb->insert( $table, $data + array( 'created_at' => $now ) );
	}

	/**
	 * @return array{access:string, scopes:string}|null
	 * @throws \RuntimeException if the stored value can't be decrypted
	 */
	public static function load( int $site_id ): ?array {
		global $wpdb;
		$table = Installer::table( 'credentials' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || ! $row->access_token ) {
			return null;
		}
		return array(
			'access' => Vault::decrypt( (string) $row->access_token, "site:{$site_id}:access" ),
			'scopes' => (string) $row->scopes,
		);
	}

	public static function delete( int $site_id ): void {
		global $wpdb;
		$wpdb->delete( Installer::table( 'credentials' ), array( 'site_id' => $site_id ) );
	}
}
