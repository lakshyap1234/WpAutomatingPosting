<?php
/**
 * Tables, keys and scheduled clean-up for the Connector.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

use CPub\Connector\OAuth\Keys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	public const SCHEMA_VERSION = 3;
	public const SCHEMA_OPTION  = 'cpub_connector_schema_version';
	public const TABLES         = array( 'grants', 'tokens', 'log' );
	public const DAILY_HOOK     = 'cpub_connector_daily';

	public static function activate(): void {
		self::install();
		Keys::ensure();
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/** Deactivating the plugin cuts every connection, as promised on the consent screen. */
	public static function deactivate(): void {
		if ( ! self::missing_tables() ) {
			OAuth\Grants::revoke_all( 'plugin_deactivated', get_current_user_id() );
		}
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	public static function maybe_upgrade(): void {
		$from = (int) get_option( self::SCHEMA_OPTION, 0 );
		if ( $from < self::SCHEMA_VERSION ) {
			self::activate();
			if ( $from > 0 && $from < 3 ) {
				// 0.5.0: the agency is limited to what it created, so mark what it created before.
				$user = PublisherUser::get();
				Rest\Ownership::mark_legacy( $user ? $user->ID : 0 );
			}
		}
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cpub_connector_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$grants  = self::table( 'grants' );
		$tokens  = self::table( 'tokens' );
		$log     = self::table( 'log' );

		dbDelta(
			array(
				// A connection: one approval by a site administrator.
				"CREATE TABLE {$grants} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  client_id varchar(100) NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  approved_by bigint(20) unsigned NOT NULL,
  scopes varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  created_at datetime NOT NULL,
  activated_at datetime NULL,
  last_used_at datetime NULL,
  revoked_at datetime NULL,
  revoked_by bigint(20) unsigned NULL,
  revoked_reason varchar(50) NOT NULL DEFAULT '',
  can_publish tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY client_status (client_id,status)
) {$collate};",
				// Codes and tokens. Only a SHA-256 hash of each is stored, so a copy
				// of the database can't be used to call the site.
				"CREATE TABLE {$tokens} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  grant_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NULL,
  kind varchar(10) NOT NULL,
  token_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime NULL,
  revoked_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY grant_kind (grant_id,kind),
  KEY parent_id (parent_id),
  KEY expires_at (expires_at)
) {$collate};",
				// What the agency did on this site, shown to the site's administrators.
				"CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  grant_id bigint(20) unsigned NULL,
  type varchar(20) NOT NULL,
  action varchar(191) NOT NULL,
  status smallint(5) unsigned NOT NULL DEFAULT 0,
  detail varchar(255) NOT NULL DEFAULT '',
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY grant_id (grant_id)
) {$collate};",
			)
		);
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/** @return string[] */
	public static function missing_tables(): array {
		global $wpdb;
		$missing = array();
		foreach ( self::TABLES as $name ) {
			$table = self::table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				$missing[] = $table;
			}
		}
		return $missing;
	}

	public static function drop_tables(): void {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( self::table( $name ) ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( self::SCHEMA_OPTION );
	}

	/** Daily: remove expired codes and tokens, abandoned approvals and old log entries. */
	public static function cleanup(): void {
		global $wpdb;
		$tokens = self::table( 'tokens' );
		$grants = self::table( 'grants' );
		$log    = self::table( 'log' );
		$now    = time();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tokens} WHERE expires_at < %s", gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$grants} WHERE status = 'pending' AND created_at < %s", gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$log} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', $now - ActivityLog::RETENTION_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
