<?php
/**
 * Settings > Content Publisher: the connection, Disconnect, readiness checks and activity log.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Admin;

use CPub\Connector\ActivityLog;
use CPub\Connector\OAuth\Agency;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\Scopes;
use CPub\Connector\PublisherUser;
use CPub\Connector\Status\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StatusPage {

	public const SLUG = 'cpub-connector';

	private const ICONS = array(
		Checks::OK    => array( 'yes-alt', '#00a32a', 'OK' ),
		Checks::WARN  => array( 'warning', '#dba617', 'Warning' ),
		Checks::ERROR => array( 'dismiss', '#d63638', 'Problem' ),
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CPUB_CONNECTOR_FILE ), array( $this, 'action_links' ) );
	}

	public function add_page(): void {
		add_options_page( 'Content Publisher Connector', 'Content Publisher', 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">Settings</a>' );
		return $links;
	}

	private static function when( ?string $gmt ): string {
		return $gmt ? get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : 'never';
	}

	public function render(): void {
		$agency = Agency::name();
		$grant  = Grants::active();
		?>
		<div class="wrap">
			<h1>Content Publisher Connector</h1>
			<?php if ( isset( $_GET['cpub_disconnected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible" id="cpub-disconnected-notice"><p>Disconnected. <?php echo esc_html( $agency ); ?> can no longer reach this site.</p></div>
			<?php endif; ?>
			<p>Lets <?php echo esc_html( $agency ); ?> create <strong>draft</strong> posts and upload images on this site, after an administrator approves. It can't publish, change anything else, or log in here.</p>

			<h2>Connection</h2>
			<div id="cpub-connection" data-state="<?php echo $grant ? 'connected' : 'disconnected'; ?>">
			<?php if ( $grant ) : ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Status</th><td><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> Connected to <strong><?php echo esc_html( $agency ); ?></strong></td></tr>
					<tr><th scope="row">Approved by</th><td><?php echo esc_html( get_userdata( (int) $grant->approved_by )->display_name ?? 'unknown' ); ?>, <?php echo esc_html( self::when( $grant->activated_at ) ); ?></td></tr>
					<tr><th scope="row">Last used</th><td><?php echo esc_html( self::when( $grant->last_used_at ) ); ?></td></tr>
					<?php $author = get_userdata( (int) $grant->user_id ); ?>
					<tr><th scope="row">Posts appear under</th><td id="cpub-post-as"><?php echo $author ? esc_html( $author->display_name . ' (' . implode( ', ', array_map( 'ucfirst', (array) $author->roles ) ) . ')' ) : 'user missing'; ?><br><span class="description">To choose someone else, ask the agency to reconnect; you choose on the approval screen.</span></td></tr>
					<tr><th scope="row">Publishing directly</th><td id="cpub-publishing" data-allowed="<?php echo Grants::can_publish( $grant ) ? '1' : '0'; ?>">
						<?php echo Grants::can_publish( $grant ) ? 'Allowed: the agency publishes and schedules its own posts, and can correct or unpublish them.' : 'Not allowed: posts arrive as drafts for your editors to publish.'; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:6px">
							<input type="hidden" name="action" value="<?php echo esc_attr( ConnectionActions::PUBLISHING ); ?>">
							<input type="hidden" name="allow" value="<?php echo Grants::can_publish( $grant ) ? '0' : '1'; ?>">
							<?php wp_nonce_field( ConnectionActions::PUBLISHING ); ?>
							<button type="submit" class="button" id="cpub-toggle-publishing"><?php echo Grants::can_publish( $grant ) ? 'Stop direct publishing' : 'Allow direct publishing'; ?></button>
						</form></td></tr>
					<tr><th scope="row">Allowed</th><td><?php echo esc_html( implode( '; ', array_map( fn( $s ) => Scopes::LABELS[ $s ] ?? $s, Grants::scopes( $grant ) ) ) ); ?></td></tr>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Disconnect <?php echo esc_js( $agency ); ?>? They will immediately lose access to this site.');">
					<input type="hidden" name="action" value="<?php echo esc_attr( ConnectionActions::ACTION ); ?>">
					<?php wp_nonce_field( ConnectionActions::ACTION ); ?>
					<button type="submit" class="button button-secondary" id="cpub-disconnect" style="color:#b32d2e;border-color:#b32d2e">Disconnect</button>
				</form>
			<?php else : ?>
				<p>Not connected. The agency starts the connection from their side; you'll then be asked here to approve it.</p>
			<?php endif; ?>
			</div>

			<h2>Readiness</h2>
			<?php $this->render_checks(); ?>

			<h2>Activity</h2>
			<p class="description">Everything <?php echo esc_html( $agency ); ?> did on this site in the last <?php echo (int) ActivityLog::RETENTION_DAYS; ?> days (newest first, up to 50 entries).</p>
			<?php $this->render_log(); ?>
		</div>
		<?php
	}

	private function render_checks(): void {
		?>
		<table class="widefat striped" style="max-width:960px" id="cpub-status">
			<thead><tr><th style="width:230px">Check</th><th style="width:100px">Result</th><th>Details</th></tr></thead>
			<tbody>
			<?php foreach ( Checks::run() as $check ) : ?>
				<?php [ $icon, $color, $word ] = self::ICONS[ $check['status'] ]; ?>
				<tr data-check="<?php echo esc_attr( $check['id'] ); ?>" data-status="<?php echo esc_attr( $check['status'] ); ?>">
					<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
					<td><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="color:<?php echo esc_attr( $color ); ?>"></span> <?php echo esc_html( $word ); ?></td>
					<td><?php echo esc_html( $check['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_log(): void {
		$rows = ActivityLog::recent( 50 );
		if ( ! $rows ) {
			echo '<p id="cpub-log-empty">Nothing yet.</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:960px" id="cpub-log">
			<thead><tr><th style="width:170px">When</th><th>What</th><th style="width:70px">Result</th><th>Details</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				$ok    = ActivityLog::EVENT === $row->type ? ( 0 === (int) $row->status && ! str_starts_with( $row->detail, 'Security:' ) ) : ( (int) $row->status < 400 );
				$actor = $row->actor_id ? get_userdata( (int) $row->actor_id ) : false;
				?>
				<tr data-type="<?php echo esc_attr( $row->type ); ?>" data-action="<?php echo esc_attr( $row->action ); ?>" data-status="<?php echo (int) $row->status; ?>">
					<td><?php echo esc_html( self::when( $row->created_at ) ); ?></td>
					<td><code><?php echo esc_html( $row->action ); ?></code></td>
					<td><?php echo $row->status ? (int) $row->status : ''; ?> <span class="dashicons dashicons-<?php echo $ok ? 'yes' : 'no-alt'; ?>" style="color:<?php echo $ok ? '#00a32a' : '#d63638'; ?>"></span></td>
					<td><?php echo esc_html( trim( $row->detail . ( $actor ? ' (by ' . $actor->display_name . ')' : '' ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
