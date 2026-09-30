<?php
/**
 * Content Publisher > Posts: every uploaded post, its status, and what to do
 * next. Refreshes itself while posts are being processed.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Jobs\Review;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PostsPage {

	public const SLUG    = 'cpub-publisher-posts';
	public const ACTIONS = array( 'cpub_job_retry', 'cpub_job_delete', 'cpub_job_plain', 'cpub_job_send' );

	public static function register(): void {
		foreach ( self::ACTIONS as $a ) {
			add_action( 'admin_post_' . $a, array( self::class, 'handle' ) );
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function action_url( string $action, int $job_id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => $action, 'job' => $job_id ), admin_url( 'admin-post.php' ) ), $action . '_' . $job_id );
	}

	/** Row actions (admin-post.php). */
	public static function handle(): void {
		$action = sanitize_key( $_REQUEST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$id     = (int) ( $_REQUEST['job'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Capabilities::REVIEW ) ) {
			wp_die( 'Only Content Publisher staff can do this.', 403 );
		}
		check_admin_referer( $action . '_' . $id );
		$job = Jobs::get( $id );
		if ( ! $job ) {
			Notices::add( 'error', 'That post no longer exists.' );
		} elseif ( 'cpub_job_retry' === $action ) {
			Notices::add( Processor::retry( $job ) ? 'success' : 'error', Jobs::FAILED === $job->status ? sprintf( '“%s” is back in the queue.', $job->title ) : 'Only a failed post can be retried.' );
		} elseif ( 'cpub_job_send' === $action ) {
			$ok = \CPub\Publisher\Jobs\Sender::retry( $job );
			Notices::add( $ok ? 'success' : 'error', $ok ? sprintf( 'Sending “%s” again.', $job->title ) : 'Only a post whose sending failed can be sent again.' );
		} elseif ( 'cpub_job_plain' === $action ) {
			$ok = Processor::plain( $job );
			Notices::add( $ok ? 'success' : 'error', $ok ? sprintf( '“%s” is ready for review as plain paragraphs.', $job->title ) : 'It couldn\'t be set out as plain paragraphs.' );
		} elseif ( 'cpub_job_delete' === $action ) {
			$r = Review::delete( $job );
			Notices::add( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : sprintf( 'Deleted “%s” and its files.', $job->title ) );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back && ! str_contains( $back, 'admin-post.php' ) ? $back : self::url() );
		if ( apply_filters( 'cpub_publisher_exit_after_redirect', true ) ) {
			exit;
		}
	}

	public function render(): void {
		Processor::recover_stale();
		$table = new PostsListTable();
		$table->prepare_items();
		$batch = sanitize_key( $_GET['batch'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap" id="cpub-posts">
			<h1 class="wp-heading-inline">Posts</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . AddPostsPage::SLUG ) ); ?>" class="page-title-action">Add posts</a>
			<hr class="wp-header-end">
			<?php Notices::render(); ?>
			<?php if ( '' !== $batch ) : ?>
				<p>Showing the posts from one upload. <a href="<?php echo esc_url( self::url() ); ?>">Show all posts</a></p>
			<?php endif; ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php if ( '' !== $batch ) : ?>
					<input type="hidden" name="batch" value="<?php echo esc_attr( $batch ); ?>">
				<?php endif; ?>
				<?php $table->search_box( 'Search posts', 'cpub-search' ); ?>
				<?php $table->display(); ?>
			</form>
			<?php if ( $table->pending_ids() ) : ?>
				<p class="description" id="cpub-refresh-note">This page updates itself while posts are processed or sent.</p>
				<script>
				( function () {
					var ids = <?php echo wp_json_encode( $table->pending_ids() ); ?>;
					var url = <?php echo wp_json_encode( rest_url( 'cpub/v1/jobs/status' ) ); ?>;
					var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
					function poll() {
						fetch( url + ( url.indexOf( '?' ) < 0 ? '?' : '&' ) + 'ids=' + ids.join( ',' ), { headers: { 'X-WP-Nonce': nonce }, credentials: 'same-origin' } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( d ) {
								var changed = ids.some( function ( id ) { return ! d[ id ] || [ 'queued', 'processing', 'approved', 'sending' ].indexOf( d[ id ].status ) < 0; } );
								if ( changed ) { location.reload(); } else { setTimeout( poll, 8000 ); }
							} )
							.catch( function () { setTimeout( poll, 15000 ); } );
					}
					setTimeout( poll, 5000 );
				} )();
				</script>
			<?php endif; ?>
		</div>
		<?php
	}
}
