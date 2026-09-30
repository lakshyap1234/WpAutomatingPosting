<?php
/**
 * Private storage for client files (source documents, images).
 *
 * Not the Media Library: files there are public. These live under
 * uploads/cpub-publisher/, which denies web access on Apache and IIS; on nginx
 * (which ignores those files) each job's folder and every file name carry
 * random parts, so nothing can be guessed. Files are only ever served to
 * logged-in reviewers, through AssetController.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Storage {

	public const DIR = 'cpub-publisher';

	/** Absolute path of the storage root (no trailing slash). */
	public static function base(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( $uploads['basedir'] ) . '/' . self::DIR;
	}

	/** Create the root with its deny rules. Safe to call repeatedly. */
	public static function protect(): void {
		$base = self::base();
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			throw new \RuntimeException( 'Could not create the private storage folder in uploads. Check the folder permissions.' );
		}
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "# Client files: never served directly.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! is_file( "{$base}/{$name}" ) ) {
				file_put_contents( "{$base}/{$name}", $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** A new, unguessable folder for one job. Returns its path relative to the root. */
	public static function new_job_dir( int $job_id ): string {
		self::protect();
		$rel = 'job-' . $job_id . '-' . bin2hex( random_bytes( 12 ) );
		if ( ! wp_mkdir_p( self::base() . '/' . $rel ) ) {
			throw new \RuntimeException( 'Could not create a storage folder for the post.' );
		}
		file_put_contents( self::base() . '/' . $rel . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $rel;
	}

	/**
	 * Store bytes under a random name in a job folder.
	 *
	 * @return string path relative to the root
	 */
	/**
	 * The only extensions a stored file can have. Never one a server might run
	 * (.php, .phtml, .phar…): these folders are web-reachable on nginx.
	 */
	public const EXTENSIONS = array( 'txt', 'md', 'docx', 'jpg', 'png', 'gif', 'webp' );

	public static function put( string $dir, string $ext, string $bytes ): string {
		$ext = strtolower( $ext );
		if ( ! in_array( $ext, self::EXTENSIONS, true ) ) {
			$ext = ''; // stored without an extension; the database keeps the real type
		}
		$rel = $dir . '/' . bin2hex( random_bytes( 12 ) ) . ( '' !== $ext ? '.' . $ext : '' );
		$abs = self::path( $rel );
		if ( null === $abs || false === file_put_contents( $abs, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			throw new \RuntimeException( 'Could not save the file.' );
		}
		return $rel;
	}

	/** Absolute path for a stored relative path, or null if it would leave the root. */
	public static function path( string $rel ): ?string {
		if ( '' === $rel || str_contains( $rel, '..' ) || str_contains( $rel, "\0" ) || ! preg_match( '#^job-\d+-[a-f0-9]{24}(?:/[a-f0-9]{24}(?:\.(?:txt|md|docx|jpg|png|gif|webp))?)?$#', $rel ) ) {
			return null;
		}
		return self::base() . '/' . $rel;
	}

	public static function read( string $rel ): ?string {
		$abs = self::path( $rel );
		if ( null === $abs || ! is_file( $abs ) ) {
			return null;
		}
		$bytes = file_get_contents( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false === $bytes ? null : $bytes;
	}

	public static function delete( string $rel ): void {
		$abs = self::path( $rel );
		if ( null !== $abs && is_file( $abs ) ) {
			wp_delete_file( $abs );
		}
	}

	/** Remove a job folder and everything in it. */
	public static function delete_dir( string $dir ): void {
		$abs = self::path( $dir );
		if ( null === $abs || ! is_dir( $abs ) ) {
			return;
		}
		foreach ( (array) scandir( $abs ) as $name ) { // not glob(): GLOB_BRACE is missing on some systems
			if ( is_file( "{$abs}/{$name}" ) ) {
				wp_delete_file( "{$abs}/{$name}" );
			}
		}
		rmdir( $abs ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
