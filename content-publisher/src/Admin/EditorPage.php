<?php
/**
 * The review editor for one post (a React app, built into build/editor.js).
 * Reached from Content Publisher > Posts; not in the menu itself.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Jobs\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EditorPage {

	public const SLUG   = 'cpub-publisher-review';
	public const HANDLE = 'cpub-editor';

	public static function url( int $job_id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&job=' . $job_id );
	}

	private static function job_id(): int {
		return (int) ( $_GET['job'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** On the page's load hook: scripts and styles. */
	public static function enqueue(): void {
		$dir   = dirname( __DIR__, 2 ) . '/build/';
		$asset = is_file( $dir . 'editor.asset.php' ) ? require $dir . 'editor.asset.php' : null;
		if ( ! is_array( $asset ) ) {
			return;
		}
		$url = plugins_url( 'build/', dirname( __DIR__, 2 ) . '/content-publisher.php' );
		wp_enqueue_script( self::HANDLE, $url . 'editor.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::HANDLE, $url . 'editor.css', array( 'wp-components' ), $asset['version'] );
		wp_add_inline_script(
			self::HANDLE,
			'window.cpubEditor = ' . wp_json_encode(
				array(
					'jobId'    => self::job_id(),
					'postsUrl' => admin_url( 'admin.php?page=' . PostsPage::SLUG ),
					'addUrl'   => admin_url( 'admin.php?page=' . AddPostsPage::SLUG ),
					'maxImage' => min( wp_max_upload_size(), \CPub\Publisher\Jobs\Assets::MAX_IMAGE_BYTES ),
				)
			) . ';',
			'before'
		);
	}

	public function render(): void {
		$job = Jobs::get( self::job_id() );
		echo '<div class="wrap cpub-editor-wrap">';
		if ( ! $job ) {
			echo '<h1>Post not found</h1><p>It may have been deleted. <a href="' . esc_url( admin_url( 'admin.php?page=' . PostsPage::SLUG ) ) . '">Back to Posts</a></p></div>';
			return;
		}
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			echo '<h1>' . esc_html( $job->title ) . '</h1><div class="notice notice-error"><p>The editor files are missing from the plugin (build/editor.js). Reinstall the plugin from the release zip.</p></div></div>';
			return;
		}
		echo '<div id="cpub-editor-root"><p>Loading the editor…</p></div></div>';
	}

	public static function can_open(): bool {
		return current_user_can( Capabilities::REVIEW );
	}
}
