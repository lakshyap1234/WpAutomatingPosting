<?php
/**
 * Database tables, capabilities and schema upgrades.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	/** Bump when the table definitions change; dbDelta applies the difference. */
	public const SCHEMA_VERSION = 4;
	public const SCHEMA_OPTION  = 'cpub_publisher_schema_version';

	public const TABLES = array( 'sites', 'credentials', 'jobs', 'assets', 'events' );

	public static function activate(): void {
		self::install();
		Capabilities::grant();
	}

	public static function maybe_upgrade(): void {
		global $wpdb;
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return;
		}
		// Right after an update several requests arrive at once; only one may alter
		// the tables (a second dbDelta would try to add the same columns again).
		$lock = 'cpub_upgrade_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return; // another request is upgrading; this one carries on with the old schema
		}
		try {
			$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SCHEMA_OPTION ) );
			if ( $current < self::SCHEMA_VERSION ) {
				self::install();
				Capabilities::grant();
				if ( $current > 0 && $current < 4 ) {
					Jobs\Sender::mark_legacy_deleted_files(); // 0.6.0: see there
				}
			}
			wp_cache_delete( self::SCHEMA_OPTION, 'options' );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cpub_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema( $wpdb->get_charset_collate() ) );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/** @return string[] Tables that should exist but don't. */
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

	/**
	 * dbDelta is picky: one column per line, two spaces after PRIMARY KEY,
	 * KEY rather than INDEX.
	 *
	 * @return string[]
	 */
	private static function schema( string $collate ): array {
		$sites       = self::table( 'sites' );
		$credentials = self::table( 'credentials' );
		$jobs        = self::table( 'jobs' );
		$assets      = self::table( 'assets' );
		$events      = self::table( 'events' );

		return array(
			// One row per client site connected through its Connector.
			"CREATE TABLE {$sites} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL DEFAULT '',
  url varchar(190) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  connector_version varchar(20) NOT NULL DEFAULT '',
  settings longtext NULL,
  endpoints longtext NULL,
  remote_user varchar(190) NOT NULL DEFAULT '',
  can_publish tinyint(1) NOT NULL DEFAULT 0,
  connected_at datetime NULL,
  last_check_at datetime NULL,
  last_error text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY url (url),
  KEY status (status)
) {$collate};",
			// OAuth tokens for a site, encrypted with CPUB_PUBLISHER_KEY. Never stored in plain text.
			"CREATE TABLE {$credentials} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  client_id varchar(100) NOT NULL DEFAULT '',
  access_token text NULL,
  refresh_token text NULL,
  access_expires_at datetime NULL,
  scopes varchar(255) NOT NULL DEFAULT '',
  remote_user_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY site_id (site_id)
) {$collate};",
			// One row per uploaded post, through review to sending.
			"CREATE TABLE {$jobs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  site_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'queued',
  title varchar(255) NOT NULL DEFAULT '',
  source_name varchar(255) NOT NULL DEFAULT '',
  source_format varchar(10) NOT NULL DEFAULT '',
  source_sha256 char(64) NOT NULL DEFAULT '',
  batch_id char(32) NOT NULL DEFAULT '',
  revision int(10) unsigned NOT NULL DEFAULT 0,
  markdown longtext NULL,
  structure_map longtext NULL,
  warnings longtext NULL,
  data longtext NULL,
  review_note text NULL,
  reviewed_at datetime NULL,
  remote_post_id bigint(20) unsigned NULL,
  remote_post_status varchar(20) NOT NULL DEFAULT '',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error text NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  reviewed_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY site_status (site_id,status),
  KEY status (status),
  KEY source_sha256 (source_sha256),
  KEY batch_id (batch_id)
) {$collate};",
			// Files belonging to a job (source document, images). Deleted once the post is published.
			"CREATE TABLE {$assets} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL,
  origin varchar(20) NOT NULL DEFAULT 'upload',
  filename varchar(255) NOT NULL DEFAULT '',
  mime varchar(100) NOT NULL DEFAULT '',
  path varchar(500) NOT NULL DEFAULT '',
  size bigint(20) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  remote_media_id bigint(20) unsigned NULL,
  deleted_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY job_id (job_id)
) {$collate};",
			// Audit trail: who did what, when.
			"CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint(20) unsigned NULL,
  site_id bigint(20) unsigned NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(50) NOT NULL,
  message text NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY job_id (job_id),
  KEY site_id (site_id),
  KEY created_at (created_at)
) {$collate};",
		);
	}
}
