<?php
/**
 * Audit log (the events table): who did what, to which site, when.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Support;

use CPub\Publisher\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Events {

	public static function log( string $type, string $message, ?int $site_id = null, ?int $job_id = null, array $context = array() ): void {
		global $wpdb;
		$wpdb->insert(
			Installer::table( 'events' ),
			array(
				'job_id'     => $job_id,
				'site_id'    => $site_id,
				'user_id'    => get_current_user_id(),
				'type'       => $type,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context ) : null,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/** @return object[] newest first */
	public static function for_site( int $site_id, int $limit = 20 ): array {
		global $wpdb;
		$table = Installer::table( 'events' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE site_id = %d ORDER BY id DESC LIMIT %d", $site_id, $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return object[] the newest $limit events, oldest first */
	public static function for_job( int $job_id, int $limit = 200 ): array {
		global $wpdb;
		$table = Installer::table( 'events' );
		// The newest $limit, in time order.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id DESC LIMIT %d", $job_id, $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_reverse( $rows );
	}
}
