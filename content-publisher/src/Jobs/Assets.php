<?php
/**
 * A job's files (the assets table): its source document and its images.
 *
 * kind:   source | image
 * origin: upload (added with the post) | docx (taken out of a Word file) | editor (added during review)
 * filename is the name the file had (what [IMAGE: name] lines refer to);
 * path is where it's stored (random, see Storage).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

use CPub\Publisher\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Assets {

	public const SOURCE = 'source';
	public const IMAGE  = 'image';

	/** Image types we accept, by extension => mime. SVG is left out: it can carry scripts. */
	public const IMAGE_TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	public const MAX_IMAGE_BYTES = 10485760; // 10 MB

	private static function table(): string {
		return Installer::table( 'assets' );
	}

	/**
	 * Store a file for a job and record it.
	 *
	 * @throws \RuntimeException when it can't be stored
	 */
	public static function add( object $job, string $kind, string $origin, string $filename, string $bytes, string $mime ): int {
		global $wpdb;
		$dir = (string) ( $job->data['dir'] ?? '' );
		if ( '' === $dir ) {
			throw new \RuntimeException( 'The post has no storage folder.' );
		}
		// The stored name's extension comes from what the file is, never from the
		// name it was uploaded with ("photo.php" that is really a PNG is stored as .png).
		$ext  = self::IMAGE === $kind ? ( self::sniff_image( $bytes )['ext'] ?? '' ) : strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$path = Storage::put( $dir, $ext, $bytes );
		$ok   = $wpdb->insert(
			self::table(),
			array(
				'job_id'     => (int) $job->id,
				'kind'       => $kind,
				'origin'     => $origin,
				'filename'   => mb_substr( $filename, 0, 255 ),
				'mime'       => mb_substr( $mime, 0, 100 ),
				'path'       => $path,
				'size'       => strlen( $bytes ),
				'sha256'     => hash( 'sha256', $bytes ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			Storage::delete( $path );
			throw new \RuntimeException( 'Could not record the file: ' . $wpdb->last_error );
		}
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d AND deleted_at IS NULL", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/** @return object[] */
	public static function for_job( int $job_id, ?string $kind = null ): array {
		global $wpdb;
		$table = self::table();
		$rows  = $kind
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND kind = %s AND deleted_at IS NULL ORDER BY id", $job_id, $kind ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND deleted_at IS NULL ORDER BY id", $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $rows ?: array();
	}

	public static function source( int $job_id ): ?object {
		return self::for_job( $job_id, self::SOURCE )[0] ?? null;
	}

	public static function bytes( object $asset ): ?string {
		return Storage::read( (string) $asset->path );
	}

	/** Remove one file (e.g. an image the reviewer replaced). */
	public static function delete( object $asset ): void {
		global $wpdb;
		Storage::delete( (string) $asset->path );
		$wpdb->update( self::table(), array( 'deleted_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $asset->id ) );
	}

	public static function delete_for_job( int $job_id ): void {
		global $wpdb;
		foreach ( self::for_job( $job_id ) as $a ) {
			Storage::delete( (string) $a->path );
		}
		$wpdb->delete( self::table(), array( 'job_id' => $job_id ), array( '%d' ) );
	}

	/**
	 * The image type of these bytes, from their content (not the name), or null.
	 *
	 * @return ?array{ext:string, mime:string}
	 */
	public static function sniff_image( string $bytes ): ?array {
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
		$ext  = array_search( $mime, self::IMAGE_TYPES, true );
		return false !== $ext && $mime ? array( 'ext' => 'jpeg' === $ext ? 'jpg' : $ext, 'mime' => $mime ) : null;
	}
}
