<?php
/**
 * Posts in progress (the jobs table).
 *
 * Status:
 *   queued      uploaded, waiting to be processed
 *   processing  being read and structured
 *   review      ready for a reviewer
 *   failed      processing failed (last_error says why); Retry puts it back in the queue
 *   approved    a reviewer approved it; waiting for the sender (or for a retry)
 *   rejected    a reviewer rejected it (review_note says why); can be reopened
 *   sending     images and the draft are being sent to the client site
 *   sent        the draft is on the client site (remote_post_id)
 *   send_failed sending failed for good (last_error says why); can be retried or reopened
 *   published   the client published the draft; our copies of the files are deleted
 *
 * Every write increments `revision`, so an editor saving an older copy is
 * refused instead of overwriting someone else's change.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

use CPub\Publisher\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Jobs {

	public const QUEUED     = 'queued';
	public const PROCESSING = 'processing';
	public const REVIEW     = 'review';
	public const FAILED     = 'failed';
	public const APPROVED    = 'approved';
	public const REJECTED    = 'rejected';
	public const SENDING     = 'sending';
	public const SENT        = 'sent';
	public const SEND_FAILED = 'send_failed';
	public const PUBLISHED   = 'published';

	public const LABELS = array(
		self::QUEUED     => 'Queued',
		self::PROCESSING => 'Processing',
		self::REVIEW     => 'Ready for review',
		self::FAILED     => 'Failed',
		self::APPROVED    => 'Approved, waiting to send',
		self::REJECTED    => 'Rejected',
		self::SENDING     => 'Sending',
		self::SENT        => 'Sent as a draft',
		self::SEND_FAILED => 'Sending failed',
		self::PUBLISHED   => 'Published',
	);

	/** The status as shown: a sent post the site has scheduled reads "Scheduled". */
	public static function label( object $job ): string {
		if ( self::SENT === $job->status && 'future' === (string) ( $job->remote_post_status ?? '' ) ) {
			return 'Scheduled';
		}
		return self::LABELS[ $job->status ] ?? (string) $job->status;
	}

	/** Columns stored as JSON. */
	private const JSON = array( 'structure_map', 'warnings', 'data' );

	private const LIMITS = array( 'title' => 255, 'source_name' => 255, 'status' => 20, 'source_format' => 10, 'batch_id' => 32 );

	private static function table(): string {
		return Installer::table( 'jobs' );
	}

	private static function encode( array $fields ): array {
		foreach ( self::JSON as $col ) {
			if ( array_key_exists( $col, $fields ) && null !== $fields[ $col ] && ! is_string( $fields[ $col ] ) ) {
				$fields[ $col ] = (string) wp_json_encode( $fields[ $col ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
		}
		foreach ( self::LIMITS as $col => $max ) {
			if ( isset( $fields[ $col ] ) && is_string( $fields[ $col ] ) ) {
				$fields[ $col ] = mb_substr( $fields[ $col ], 0, $max );
			}
		}
		return $fields;
	}

	/** Row with JSON columns decoded (structure_map: ?array, warnings: array, data: array). */
	private static function decode( ?object $row ): ?object {
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'id', 'site_id', 'revision', 'attempts', 'created_by' ) as $int ) {
			$row->$int = (int) $row->$int;
		}
		$map                = json_decode( (string) $row->structure_map, true );
		$row->structure_map = is_array( $map ) ? $map : null;
		$w                  = json_decode( (string) $row->warnings, true );
		$row->warnings      = is_array( $w ) ? $w : array();
		$d                  = json_decode( (string) $row->data, true );
		$row->data          = is_array( $d ) ? $d : array();
		return $row;
	}

	/** @param array $fields site_id, source_name, source_format, source_sha256, created_by, batch_id, title, data */
	public static function create( array $fields ): int {
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$fields = self::encode( $fields + array( 'status' => self::QUEUED, 'created_at' => $now, 'updated_at' => $now, 'revision' => 1 ) );
		if ( ! $wpdb->insert( self::table(), $fields ) ) {
			throw new \RuntimeException( 'Could not save the post: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Update a job and bump its revision.
	 *
	 * @param ?int         $expect_revision only update if the job is still at this revision
	 * @param string[]|null $from_statuses  only update if the job is in one of these statuses
	 * @return bool false if the job changed meanwhile (or doesn't exist)
	 */
	public static function update( int $id, array $fields, ?int $expect_revision = null, ?array $from_statuses = null ): bool {
		global $wpdb;
		$table  = self::table();
		$fields = self::encode( $fields );
		unset( $fields['id'], $fields['revision'] );
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$sets = array();
		$args = array();
		foreach ( $fields as $col => $value ) {
			if ( ! preg_match( '/^[a-z_0-9]+$/', $col ) ) {
				throw new \InvalidArgumentException( "Bad column {$col}" );
			}
			if ( null === $value ) {
				$sets[] = "`{$col}` = NULL";
			} else {
				$sets[] = "`{$col}` = " . ( is_int( $value ) ? '%d' : '%s' );
				$args[] = $value;
			}
		}
		$sets[] = '`revision` = `revision` + 1';
		$where  = 'id = %d';
		$args[] = $id;
		if ( null !== $expect_revision ) {
			$where .= ' AND revision = %d';
			$args[] = $expect_revision;
		}
		if ( null !== $from_statuses ) {
			if ( ! $from_statuses ) {
				return false;
			}
			$where .= ' AND status IN (' . implode( ',', array_fill( 0, count( $from_statuses ), '%s' ) ) . ')';
			$args   = array_merge( $args, array_values( $from_statuses ) );
		}
		$sql = "UPDATE {$table} SET " . implode( ', ', $sets ) . " WHERE {$where}";
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array{site_id?:int, status?:string|string[], search?:string, batch_id?:string, page?:int, per_page?:int, orderby?:string, order?:string} $q
	 * @return array{0: object[], 1: int} [rows, total]
	 */
	public static function query( array $q = array() ): array {
		global $wpdb;
		$table = self::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $q['site_id'] ) ) {
			$where[] = 'site_id = %d';
			$args[]  = (int) $q['site_id'];
		}
		if ( ! empty( $q['status'] ) ) {
			$statuses = array_values( array_intersect( (array) $q['status'], array_keys( self::LABELS ) ) );
			if ( ! $statuses ) {
				return array( array(), 0 );
			}
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$args    = array_merge( $args, $statuses );
		}
		if ( ! empty( $q['batch_id'] ) ) {
			$where[] = 'batch_id = %s';
			$args[]  = (string) $q['batch_id'];
		}
		if ( isset( $q['search'] ) && '' !== trim( (string) $q['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $q['search'] ) ) . '%';
			$where[] = '(title LIKE %s OR source_name LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		$orderby  = in_array( $q['orderby'] ?? '', array( 'title', 'status', 'created_at', 'updated_at' ), true ) ? $q['orderby'] : 'created_at';
		$order    = 'asc' === strtolower( (string) ( $q['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, (int) ( $q['per_page'] ?? 20 ) ) );
		$offset   = ( max( 1, (int) ( $q['page'] ?? 1 ) ) - 1 ) * $per_page;
		$w        = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$w}", $args ) : "SELECT COUNT(*) FROM {$table} WHERE {$w}" );
		$sql   = "SELECT * FROM {$table} WHERE {$w} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, $offset ) ) ) ) ?: array();
		// phpcs:enable
		return array( array_map( array( self::class, 'decode' ), $rows ), $total );
	}

	/** @return array<string,int> status => count */
	public static function counts( ?int $site_id = null ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $site_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM {$table} WHERE site_id = %d GROUP BY status", $site_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array_fill_keys( array_keys( self::LABELS ), 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	/** The newest job for the same file on the same site, if any. */
	public static function find_duplicate( int $site_id, string $sha256 ): ?object {
		global $wpdb;
		$table = self::table();
		return self::decode( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE site_id = %d AND source_sha256 = %s ORDER BY id DESC LIMIT 1", $site_id, $sha256 ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete a job with its files and assets. Its events stay (audit trail).
	 *
	 * @param string[] $unless_statuses refuse (return false) if the job is in one of these, checked atomically
	 */
	public static function delete( int $id, array $unless_statuses = array() ): bool {
		global $wpdb;
		$job = self::get( $id );
		if ( ! $job ) {
			return false;
		}
		$table = self::table();
		$sql   = "DELETE FROM {$table} WHERE id = %d";
		$args  = array( $id );
		if ( $unless_statuses ) {
			$sql .= ' AND status NOT IN (' . implode( ',', array_fill( 0, count( $unless_statuses ), '%s' ) ) . ')';
			$args = array_merge( $args, array_values( $unless_statuses ) );
		}
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( $sql, $args ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return false;
		}
		Assets::delete_for_job( $id );
		if ( ! empty( $job->data['dir'] ) ) {
			Storage::delete_dir( (string) $job->data['dir'] );
		}
		return true;
	}
}
