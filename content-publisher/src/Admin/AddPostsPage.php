<?php
/**
 * Content Publisher > Add posts: choose the client site, upload the posts and
 * their images. Each post is then processed in the background.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Intake\IntakeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AddPostsPage {

	public const SLUG   = 'cpub-publisher-add';
	public const ACTION = 'cpub_add_posts';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	public static function handle(): void {
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			wp_die( 'Only Content Publisher staff can upload posts.', 403 );
		}
		check_admin_referer( self::ACTION );
		$site_id = (int) ( $_POST['site_id'] ?? 0 );
		[ $posts, $p1 ]  = Intake::from_upload( $_FILES['posts'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		[ $images, $p2 ] = Intake::from_upload( $_FILES['images'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array_merge( $p1, $p2 ) as $problem ) {
			Notices::add( 'error', $problem );
		}
		$back = admin_url( 'admin.php?page=' . self::SLUG . '&site=' . $site_id );
		if ( ! $posts && ( $p1 || $p2 ) ) {
			self::redirect( $back );
			return;
		}
		try {
			$r = Intake::submit( $site_id, $posts, $images, get_current_user_id(), ! empty( $_POST['allow_duplicates'] ) );
		} catch ( IntakeException $e ) {
			Notices::add( 'error', $e->getMessage() );
			self::redirect( $back );
			return;
		} catch ( \Throwable $e ) {
			error_log( 'Content Publisher upload: ' . $e ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			Notices::add( 'error', 'The upload failed on the server (details are in the PHP error log): ' . $e->getMessage() );
			self::redirect( $back );
			return;
		}
		foreach ( $r['skipped'] as $s ) {
			Notices::add( 'warning', "{$s['name']}: {$s['reason']}" . ( isset( $s['job'] ) ? ' Tick “Upload again” to add it anyway.' : '' ) );
		}
		if ( $r['images_unused'] ) {
			Notices::add( 'warning', 'Not used by any of the posts, so not kept: ' . implode( ', ', $r['images_unused'] ) . '. Posts refer to images with lines like [IMAGE: photo.jpg].' );
		}
		if ( ! $r['created'] ) {
			self::redirect( $back );
			return;
		}
		Notices::add( 'success', sprintf( _n( '%d post uploaded. It is processed in the background and appears as “Ready for review” when done.', '%d posts uploaded. They are processed in the background, one at a time, and appear as “Ready for review” when done.', count( $r['created'] ) ), count( $r['created'] ) ) );
		self::redirect( admin_url( 'admin.php?page=' . PostsPage::SLUG . '&batch=' . $r['batch'] ) );
	}

	private static function redirect( string $to ): void {
		wp_safe_redirect( $to );
		if ( apply_filters( 'cpub_publisher_exit_after_redirect', true ) ) {
			exit;
		}
	}

	public function render(): void {
		$sites    = Sites::all( Sites::CONNECTED );
		$selected = (int) ( $_GET['site'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap" id="cpub-add">
			<h1>Add posts</h1>
			<?php Notices::render(); ?>
			<?php if ( ! $sites ) : ?>
				<div class="notice notice-warning inline"><p>No client site is connected yet. <?php echo current_user_can( Capabilities::MANAGE ) ? '<a href="' . esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ) . '">Connect one under Client sites</a>.' : 'Ask an administrator to connect one.'; ?></p></div>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cpub-site">Client site</label></th>
						<td><select name="site_id" id="cpub-site" required>
							<?php if ( count( $sites ) > 1 ) : ?>
								<option value="">Choose…</option>
							<?php endif; ?>
							<?php foreach ( $sites as $s ) : ?>
								<option value="<?php echo (int) $s->id; ?>" <?php selected( $selected, (int) $s->id ); ?>><?php echo esc_html( ( $s->name ?: $s->url ) . ' — ' . $s->url ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="cpub-posts">Posts</label></th>
						<td><input type="file" name="posts[]" id="cpub-posts" multiple required accept=".txt,.md,.docx,text/plain,text/markdown,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
							<p class="description">One or more files: plain text (.txt), Markdown (.md) or Word (.docx). Each file is one post; its first line is the title.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="cpub-images">Images</label></th>
						<td><input type="file" name="images[]" id="cpub-images" multiple accept="image/jpeg,image/png,image/gif,image/webp">
							<p class="description">Optional. JPEG, PNG, GIF or WebP, up to 10 MB each. A post uses an image through a line like <code>[IMAGE: photo.jpg]</code>, matched by file name. Images inside a Word file are taken out automatically. More images can be added during review.</p></td>
					</tr>
					<tr>
						<th scope="row">Duplicates</th>
						<td><label><input type="checkbox" name="allow_duplicates" value="1" id="cpub-dupes"> Upload again even if the same file was already uploaded for this site</label></td>
					</tr>
				</table>
				<p class="description">This server accepts uploads up to <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?> per request.</p>
				<?php submit_button( 'Upload', 'primary', 'submit', true, array( 'id' => 'cpub-upload' ) ); ?>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
