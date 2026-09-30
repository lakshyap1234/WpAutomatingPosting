<?php
/**
 * Connections ("grants"): one per approval by a site administrator.
 *
 * pending: approved on the consent screen, code not yet exchanged
 * active:  tokens issued; at most one per agency (a new approval replaces the old)
 * revoked: disconnected, replaced, or cut off after a security problem
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

use CPub\Connector\ActivityLog;
use CPub\Connector\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Grants {

	public const PENDING = 'pending';
	public const ACTIVE  = 'active';
	public const REVOKED = 'revoked';

	/** Plain-language reasons shown in the activity log. */
	public const REASONS = array(
		'disconnected'        => 'Disconnected by a site administrator',
		'replaced'            => 'Replaced by a newer approval',
		'plugin_deactivated'  => 'The Connector plugin was deactivated',
		'revoked_by_agency'   => 'Disconnected by the agency',
		'refresh_token_reuse' => 'Security: an old refresh token was reused, so the connection was cut off',
		'code_reuse'          => 'Security: an approval code was used twice, so the connection was cut off',
		'user_elevated'       => 'Security: the Agency Publisher user had more than Author permissions',
		'user_not_allowed'    => 'The user posts appear under was deleted, lost the right to write posts, or became an administrator',
	);

	public static function create_pending( string $client_id, int $user_id, int $approved_by, array $scopes, bool $can_publish = false ): int {
		global $wpdb;
		$wpdb->insert(
			Installer::table( 'grants' ),
			array(
				'client_id'   => $client_id,
				'user_id'     => $user_id,
				'approved_by' => $approved_by,
				'scopes'      => implode( ' ', $scopes ),
				'status'      => self::PENDING,
				'created_at'  => TokenStore::now(),
				'can_publish' => $can_publish ? 1 : 0,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = Installer::table( 'grants' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function active( ?string $client_id = null ): ?object {
		global $wpdb;
		$table = Installer::table( 'grants' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE client_id = %s AND status = %s ORDER BY id DESC LIMIT 1", $client_id ?? Agency::client_id(), self::ACTIVE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/** First token exchange: the pending grant becomes the connection, replacing older ones. */
	public static function activate( int $id ): void {
		global $wpdb;
		$table   = Installer::table( 'grants' );
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, activated_at = %s WHERE id = %d AND status = %s", self::ACTIVE, TokenStore::now(), $id, self::PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== $updated ) {
			return;
		}
		$grant = self::get( $id );
		// Only older connections are replaced, so if two approvals finish at once the newer one wins.
		$older = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE client_id = %s AND status = %s AND id < %d", $grant->client_id, self::ACTIVE, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $older as $old_id ) {
			self::revoke( (int) $old_id, 'replaced', 0 );
		}
		$newer = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE client_id = %s AND status = %s AND id > %d LIMIT 1", $grant->client_id, self::ACTIVE, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $newer ) {
			self::revoke( $id, 'replaced', 0 );
			return;
		}
		ActivityLog::event( $id, 'connected', 'Connection is active; the agency received its access tokens.' );
	}

	public static function revoke( int $id, string $reason, int $by ): void {
		global $wpdb;
		$grant = self::get( $id );
		if ( ! $grant || self::REVOKED === $grant->status ) {
			return;
		}
		$wpdb->update(
			Installer::table( 'grants' ),
			array(
				'status'         => self::REVOKED,
				'revoked_at'     => TokenStore::now(),
				'revoked_by'     => $by,
				'revoked_reason' => $reason,
			),
			array( 'id' => $id )
		);
		TokenStore::revoke_grant( $id );
		ActivityLog::event( $id, 'revoked', self::REASONS[ $reason ] ?? $reason, $by );
	}

	public static function revoke_all( string $reason, int $by ): int {
		global $wpdb;
		$table = Installer::table( 'grants' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status <> %s", self::REVOKED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $ids as $id ) {
			self::revoke( (int) $id, $reason, $by );
		}
		return count( $ids );
	}

	/** Records use, at most every five minutes, to keep writes down. */
	public static function touch( object $grant ): void {
		global $wpdb;
		if ( $grant->last_used_at && strtotime( $grant->last_used_at . ' UTC' ) > time() - 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		$wpdb->update( Installer::table( 'grants' ), array( 'last_used_at' => TokenStore::now() ), array( 'id' => (int) $grant->id ) );
	}

	/** Whether the site lets the agency publish, schedule and unpublish its own posts. */
	public static function can_publish( object $grant ): bool {
		return ! empty( $grant->can_publish );
	}

	public static function set_can_publish( int $id, bool $allowed, int $by ): void {
		global $wpdb;
		$wpdb->update( Installer::table( 'grants' ), array( 'can_publish' => $allowed ? 1 : 0 ), array( 'id' => $id ) );
		ActivityLog::event( $id, $allowed ? 'publish_allowed' : 'publish_stopped', $allowed ? 'The agency may now publish, schedule and unpublish the posts it creates.' : 'The agency may no longer publish; it can only save drafts.', $by );
	}

	/** @return string[] */
	public static function scopes( object $grant ): array {
		return array_values( array_filter( explode( ' ', (string) $grant->scopes ) ) );
	}
}
