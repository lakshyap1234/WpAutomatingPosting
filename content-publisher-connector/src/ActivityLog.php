<?php
/**
 * Everything the agency did on this site, for the site's administrators.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

use CPub\Connector\OAuth\TokenStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ActivityLog {

	public const RETENTION_DAYS = 90;

	public const EVENT = 'event';
	public const API   = 'api';

	public static function event( ?int $grant_id, string $action, string $detail = '', int $actor_id = 0, int $status = 0 ): void {
		self::write( $grant_id, self::EVENT, $action, $status, $detail, $actor_id );
	}

	public static function api( int $grant_id, string $method, string $route, int $status, string $detail = '' ): void {
		self::write( $grant_id, self::API, strtoupper( $method ) . ' ' . $route, $status, $detail, 0 );
	}

	/** @return object[] newest first */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = Installer::table( 'log' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function write( ?int $grant_id, string $type, string $action, int $status, string $detail, int $actor_id ): void {
		global $wpdb;
		$wpdb->insert(
			Installer::table( 'log' ),
			array(
				'grant_id'   => $grant_id,
				'type'       => $type,
				'action'     => mb_substr( $action, 0, 191 ),
				'status'     => $status,
				'detail'     => mb_substr( $detail, 0, 255 ),
				'actor_id'   => $actor_id,
				'ip'         => RequestContext::ip(),
				'created_at' => TokenStore::now(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}
}
