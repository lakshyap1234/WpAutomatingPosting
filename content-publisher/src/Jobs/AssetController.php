<?php
/**
 * Shows a post's image files to logged-in reviewers (the preview needs them
 * before they're sent). Files are never reachable any other way.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

use CPub\Publisher\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AssetController {

	public const ACTION = 'cpub_asset';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'serve' ) );
	}

	public static function url( int $job_id, int $asset_id ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'job'      => $job_id,
				'asset'    => $asset_id,
				'_wpnonce' => wp_create_nonce( self::ACTION . '_' . $job_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function serve(): void {
		$job_id   = (int) ( $_GET['job'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$asset_id = (int) ( $_GET['asset'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		check_admin_referer( self::ACTION . '_' . $job_id );
		$asset = Assets::get( $asset_id );
		$bytes = $asset && (int) $asset->job_id === $job_id && Assets::IMAGE === $asset->kind ? Assets::bytes( $asset ) : null;
		// Only image types we accepted, served as that type (never as HTML).
		if ( null === $bytes || ! in_array( $asset->mime, Assets::IMAGE_TYPES, true ) ) {
			wp_die( 'Not found.', 404 );
		}
		self::send( $asset->mime, $bytes, (string) $asset->filename );
	}

	/** Separate so tests can capture the output instead of exiting. */
	public static function send( string $mime, string $bytes, string $filename ): void {
		$headers = array(
			'Content-Type'            => $mime,
			'Content-Length'          => (string) strlen( $bytes ),
			'X-Content-Type-Options'  => 'nosniff',
			'Content-Security-Policy' => "default-src 'none'",
			'Cache-Control'           => 'private, max-age=3600',
			'Content-Disposition'     => 'inline; filename="' . str_replace( array( '"', "\r", "\n" ), '', rawurlencode( $filename ) ) . '"',
		);
		if ( apply_filters( 'cpub_publisher_asset_send', true, $headers, $bytes ) ) {
			foreach ( $headers as $k => $v ) {
				header( "{$k}: {$v}" );
			}
			echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput -- binary image, type fixed above
			exit;
		}
	}
}
