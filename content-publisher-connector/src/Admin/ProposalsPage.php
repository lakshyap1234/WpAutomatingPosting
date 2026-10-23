<?php
/**
 * Posts > Proposed changes: the agency's suggestions for the site's published
 * posts and pages, each with a side-by-side comparison, to apply or reject.
 * Applying is done by the editor on this screen (Proposals::apply).
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Admin;

use CPub\Connector\OAuth\Agency;
use CPub\Connector\Proposals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProposalsPage {

	public const SLUG   = 'cpub-proposals';
	public const ACTION = 'cpub_connector_proposal';

	/** Editors and administrators. Each decision also needs the right to edit that post. */
	public const CAP = 'edit_others_posts';

	private const LABELS = array(
		'title'   => 'Title',
		'content' => 'Content',
		'excerpt' => 'Excerpt',
	);

	private const STATES = array(
		Proposals::PENDING    => 'Waiting',
		Proposals::APPLIED    => 'Applied',
		Proposals::REJECTED   => 'Rejected',
		Proposals::SUPERSEDED => 'Replaced by a newer suggestion',
	);

	private const ERRORS = array(
		'cpub_proposal_decided' => 'Someone already decided on this suggestion.',
		'cpub_proposal_gone'    => 'The original is no longer a published post or page, so the suggestion can\'t be applied.',
		'cpub_proposal_cannot'  => 'You can\'t edit this post or page.',
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'decide' ) );
	}

	public function add_page(): void {
		$count  = current_user_can( self::CAP ) ? Proposals::count_pending() : 0;
		$bubble = $count ? ' <span class="awaiting-mod">' . (int) $count . '</span>' : '';
		add_submenu_page( 'edit.php', 'Proposed changes', 'Proposed changes' . $bubble, self::CAP, self::SLUG, array( $this, 'render' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'edit.php' ) );
	}

	/** Whether the current user may decide on this proposal. */
	private static function may_decide( object $proposal ): bool {
		return get_post( (int) $proposal->post_id )
			? current_user_can( 'edit_post', (int) $proposal->post_id )
			: current_user_can( self::CAP );
	}

	/** Apply or Reject (admin-post.php). */
	public static function decide(): void {
		$id = absint( $_POST['proposal'] ?? 0 );
		check_admin_referer( self::ACTION . '-' . $id );
		$proposal = Proposals::get( $id );
		if ( ! $proposal || ! self::may_decide( $proposal ) ) {
			wp_die( 'You can\'t review this suggestion.', 403 );
		}
		$apply  = ! empty( $_POST['apply'] );
		$result = $apply
			? Proposals::apply( $proposal, get_current_user_id() )
			: Proposals::reject( $proposal, get_current_user_id(), sanitize_textarea_field( wp_unslash( (string) ( $_POST['note'] ?? '' ) ) ) );
		$args = is_wp_error( $result ) ? array( 'cpub_error' => $result->get_error_code() ) : array( 'cpub_done' => $apply ? 'applied' : 'rejected' );
		wp_safe_redirect( self::url( array( 'proposal' => $id ) + $args ) );
		if ( apply_filters( 'cpub_connector_exit_after_redirect', true ) ) {
			exit;
		}
	}

	public function render(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- display only
		$id    = absint( $_GET['proposal'] ?? 0 );
		$done  = sanitize_key( (string) ( $_GET['cpub_done'] ?? '' ) );
		$error = sanitize_key( (string) ( $_GET['cpub_error'] ?? '' ) );
		// phpcs:enable
		echo '<div class="wrap" id="cpub-proposals">';
		if ( 'applied' === $done ) {
			echo '<div class="notice notice-success" id="cpub-proposal-applied"><p>Applied. The previous version is kept in the post\'s revisions.</p></div>';
		} elseif ( 'rejected' === $done ) {
			echo '<div class="notice notice-success" id="cpub-proposal-rejected"><p>Rejected. ' . esc_html( Agency::name() ) . ' can see that it was rejected, and your reason if you gave one.</p></div>';
		} elseif ( '' !== $error ) {
			echo '<div class="notice notice-error" id="cpub-proposal-error"><p>' . esc_html( self::ERRORS[ $error ] ?? 'The suggestion couldn\'t be applied.' ) . '</p></div>';
		}
		$proposal = $id ? Proposals::get( $id ) : null;
		if ( $proposal && self::may_decide( $proposal ) ) {
			$this->render_one( $proposal );
		} else {
			$this->render_list();
		}
		echo '</div>';
	}

	private function render_list(): void {
		$rows = array_filter( Proposals::find(), fn( $p ) => self::may_decide( $p ) );
		?>
		<h1>Proposed changes</h1>
		<p><?php echo esc_html( Agency::name() ); ?> can suggest changes to your published posts and pages. Nothing changes on your site until you apply a suggestion here.</p>
		<?php if ( ! $rows ) : ?>
			<p id="cpub-proposals-empty"><em>No suggestions yet.</em></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped" id="cpub-proposals-list">
			<thead><tr><th>For</th><th>Changes</th><th>Suggested</th><th>State</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php $post = get_post( (int) $row->post_id ); ?>
				<tr data-proposal="<?php echo (int) $row->id; ?>" data-state="<?php echo esc_attr( $row->state ); ?>">
					<td><?php echo $post ? esc_html( get_the_title( $post ) ) . ' <span class="description">(' . esc_html( Proposals::TYPES[ $post->post_type ] ?? $post->post_type ) . ')</span>' : '<em>Deleted</em>'; ?></td>
					<td><?php echo esc_html( implode( ', ', array_map( fn( $f ) => self::LABELS[ $f ], Proposals::changed( $row ) ) ) ); ?></td>
					<td><?php echo esc_html( self::when( $row->created_at ) ); ?></td>
					<td><?php echo esc_html( self::STATES[ $row->state ] ?? $row->state ); ?></td>
					<td><a href="<?php echo esc_url( self::url( array( 'proposal' => (int) $row->id ) ) ); ?>"><?php echo Proposals::PENDING === $row->state ? 'Review' : 'View'; ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_one( object $proposal ): void {
		$post    = get_post( (int) $proposal->post_id );
		$target  = Proposals::target( (int) $proposal->post_id );
		$pending = Proposals::PENDING === $proposal->state;
		$decider = $proposal->decided_by ? get_userdata( (int) $proposal->decided_by ) : null;
		?>
		<h1>Suggested changes to “<?php echo esc_html( $post ? get_the_title( $post ) : '#' . (int) $proposal->post_id ); ?>”</h1>
		<p>
			From <?php echo esc_html( Agency::name() ); ?>, <?php echo esc_html( self::when( $proposal->created_at ) ); ?>.
			<?php if ( $post ) : ?>
				<a href="<?php echo esc_url( get_permalink( $post ) ); ?>">View the <?php echo esc_html( Proposals::TYPES[ $post->post_type ] ?? 'post' ); ?></a>
			<?php endif; ?>
			&middot; <a href="<?php echo esc_url( self::url() ); ?>">All suggestions</a>
		</p>

		<?php if ( ! $pending ) : ?>
			<div class="notice notice-info inline" id="cpub-proposal-state"><p>
				<?php echo esc_html( self::STATES[ $proposal->state ] ?? $proposal->state ); ?>
				<?php echo $proposal->decided_at && Proposals::SUPERSEDED !== $proposal->state ? esc_html( ' ' . self::when( $proposal->decided_at ) . ( $decider ? ' by ' . $decider->display_name : '' ) ) : ''; ?>.
				<?php echo '' !== (string) $proposal->note ? esc_html( 'Reason: ' . $proposal->note ) : ''; ?>
			</p></div>
		<?php elseif ( ! $target ) : ?>
			<div class="notice notice-warning inline" id="cpub-proposal-gone"><p>The original is no longer a published post or page, so this suggestion can only be rejected.</p></div>
		<?php elseif ( Proposals::is_stale( $proposal, $target ) ) : ?>
			<div class="notice notice-warning inline" id="cpub-proposal-stale"><p><strong>This <?php echo esc_html( Proposals::TYPES[ $target->post_type ] ); ?> was edited after the suggestion was made.</strong> Applying it replaces those edits too: check the comparison below, which is against the current version.</p></div>
		<?php endif; ?>

		<?php
		foreach ( Proposals::changed( $proposal ) as $field ) {
			$now  = $post ? (string) $post->{'post_' . $field} : '';
			$diff = wp_text_diff( $now, (string) $proposal->$field, array( 'title_left' => 'Now', 'title_right' => 'Suggested', 'show_split_view' => true ) );
			echo '<h2>' . esc_html( self::LABELS[ $field ] ) . '</h2>';
			echo $diff ? '<div class="cpub-proposal-diff" data-field="' . esc_attr( $field ) . '">' . $diff . '</div>' : '<p><em>Same as now.</em></p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_text_diff() escapes the text
		}
		?>

		<?php if ( $pending ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:24px" id="cpub-proposal-form">
				<?php wp_nonce_field( self::ACTION . '-' . (int) $proposal->id ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="proposal" value="<?php echo (int) $proposal->id; ?>">
				<?php if ( $target ) : ?>
					<p><button type="submit" name="apply" value="1" class="button button-primary" id="cpub-proposal-apply">Apply these changes</button>
					<span class="description">The current version is kept in the <?php echo esc_html( Proposals::TYPES[ $target->post_type ] ); ?>'s revisions.</span></p>
				<?php endif; ?>
				<p><label for="cpub-proposal-note">Reason for rejecting (optional, shown to <?php echo esc_html( Agency::name() ); ?>)</label><br>
					<textarea name="note" id="cpub-proposal-note" rows="2" cols="60" maxlength="<?php echo (int) Proposals::MAX_NOTE; ?>"></textarea></p>
				<p><button type="submit" name="reject" value="1" class="button" id="cpub-proposal-reject">Reject</button></p>
			</form>
		<?php endif; ?>
		<?php
	}

	private static function when( ?string $gmt ): string {
		return $gmt ? get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '';
	}
}
