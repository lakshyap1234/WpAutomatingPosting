<?php
/**
 * Content Publisher > Client sites: connect, check, disconnect, remove.
 *
 * The OAuth callback also lands on this page (…&cpub_oauth=callback), because
 * its address is what client Connectors are built to trust.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Connections\ConnectFlow;
use CPub\Publisher\Connections\Connections;
use CPub\Publisher\Connections\HealthCheck;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Crypto\Key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SitesPage {

	public const ACTIONS = array( 'cpub_connect', 'cpub_site_check', 'cpub_site_disconnect', 'cpub_site_remove' );

	public static function register(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . Menu::SLUG ) );
	}

	/** Runs before output on the page: finishes an OAuth approval. */
	public static function load(): void {
		if ( 'callback' !== ( $_GET['cpub_oauth'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- protected by the OAuth state.
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( 'Only Content Publisher managers can connect client sites.', 403 );
		}
		$result = ConnectFlow::callback( wp_unslash( $_GET ), get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( is_wp_error( $result ) ) {
			Notices::add( 'error', $result->get_error_message() );
		} else {
			Notices::add( 'success', sprintf( 'Connected to %s.', $result->name ?: $result->url ) );
		}
		self::redirect( self::url() );
	}

	/** Form actions (admin-post.php). */
	public static function handle(): void {
		$action = sanitize_key( $_REQUEST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( 'Only Content Publisher managers can change client connections.', 403 );
		}
		check_admin_referer( $action );

		if ( 'cpub_connect' === $action ) {
			if ( Key::OK !== Key::state() ) {
				Notices::add( 'error', 'Set the encryption key first (Content Publisher > Status).' );
				self::redirect( self::url() );
				return;
			}
			$to = ConnectFlow::start( (string) wp_unslash( $_POST['site_url'] ?? '' ), get_current_user_id() );
			if ( is_wp_error( $to ) ) {
				Notices::add( 'error', $to->get_error_message() );
				self::redirect( self::url() );
				return;
			}
			// The approval screen on the client's own site (host checked in Discovery).
			self::redirect( $to, false );
			return;
		}

		$site = Sites::get( absint( $_POST['site_id'] ?? 0 ) );
		if ( ! $site ) {
			Notices::add( 'error', 'That client site no longer exists.' );
			self::redirect( self::url() );
			return;
		}
		$label = $site->name ?: $site->url;
		switch ( $action ) {
			case 'cpub_site_check':
				$ok = HealthCheck::check( $site );
				$site = Sites::get( (int) $site->id );
				Notices::add( $ok ? 'success' : 'error', $ok ? "$label is connected and answering." : "$label: " . ( $site->last_error ?: 'not connected.' ) );
				break;
			case 'cpub_site_disconnect':
				$r = Connections::disconnect( $site );
				Notices::add( is_wp_error( $r ) ? 'warning' : 'success', is_wp_error( $r ) ? $r->get_error_message() : "Disconnected from $label. Its access has been revoked." );
				break;
			case 'cpub_site_remove':
				$r = Connections::remove( $site );
				Notices::add( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : "Removed $label." );
				break;
		}
		self::redirect( self::url() );
	}

	private static function redirect( string $to, bool $safe = true ): void {
		if ( $safe ) {
			wp_safe_redirect( $to );
		} else {
			wp_redirect( $to ); // phpcs:ignore WordPress.Security.SafeRedirect
		}
		if ( apply_filters( 'cpub_publisher_exit_after_redirect', true ) ) {
			exit;
		}
	}

	private static function when( ?string $gmt ): string {
		return $gmt ? get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
	}

	private static function form( string $action, int $site_id, string $label, string $class = 'button', string $confirm = '' ): void {
		printf(
			'<form method="post" action="%s" style="display:inline" %s><input type="hidden" name="action" value="%s"><input type="hidden" name="site_id" value="%d">%s<button type="submit" class="%s" data-action="%s">%s</button></form> ',
			esc_url( admin_url( 'admin-post.php' ) ),
			$confirm ? 'onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '',
			esc_attr( $action ),
			$site_id,
			wp_nonce_field( $action, '_wpnonce', true, false ),
			esc_attr( $class ),
			esc_attr( $action ),
			esc_html( $label )
		);
	}

	public function render(): void {
		$manage = current_user_can( Capabilities::MANAGE );
		$sites  = Sites::all();
		?>
		<div class="wrap">
			<h1>Client sites</h1>
			<?php Notices::render(); ?>

			<?php if ( $manage ) : ?>
				<?php if ( Key::OK !== Key::state() ) : ?>
					<div class="notice notice-error inline"><p>Set the encryption key before connecting sites: <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG . '-status' ) ); ?>">Content Publisher &rarr; Status</a>.</p></div>
				<?php endif; ?>
				<h2>Connect a client site</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cpub-connect-form">
					<input type="hidden" name="action" value="cpub_connect">
					<?php wp_nonce_field( 'cpub_connect' ); ?>
					<input type="text" inputmode="url" autocomplete="url" spellcheck="false" name="site_url" id="cpub-site-url" class="regular-text" placeholder="client-site.com" required>
					<button type="submit" class="button button-primary" id="cpub-connect"<?php disabled( Key::OK !== Key::state() ); ?>>Connect</button>
					<p class="description">The client site needs the Content Publisher Connector installed. You'll be sent to that site, where one of its administrators logs in and approves.</p>
				</form>
			<?php endif; ?>

			<h2>Sites</h2>
			<?php if ( ! $sites ) : ?>
				<p id="cpub-no-sites">No client sites yet.</p>
			<?php else : ?>
			<table class="widefat striped" id="cpub-sites">
				<thead><tr><th>Site</th><th>Status</th><th>Connected since</th><th>Last check</th><th>Posts as</th><?php if ( $manage ) : ?><th>Actions</th><?php endif; ?></tr></thead>
				<tbody>
				<?php foreach ( $sites as $site ) : ?>
					<?php
					$problem = Sites::CONNECTED === $site->status && $site->last_error;
					[ $label, $color ] = match ( true ) {
						$problem                               => array( 'Connected, with a problem', '#dba617' ),
						Sites::CONNECTED === $site->status     => array( 'Connected', '#00a32a' ),
						Sites::PENDING === $site->status       => array( 'Waiting for approval', '#72777c' ),
						default                                => array( 'Disconnected', '#d63638' ),
					};
					?>
					<tr data-site-id="<?php echo (int) $site->id; ?>" data-url="<?php echo esc_attr( $site->url ); ?>" data-status="<?php echo esc_attr( $site->status ); ?>" data-problem="<?php echo $problem ? '1' : '0'; ?>">
						<td><strong><?php echo esc_html( $site->name ?: $site->url ); ?></strong><br><a href="<?php echo esc_url( $site->url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $site->url ); ?></a></td>
						<td><span style="color:<?php echo esc_attr( $color ); ?>">&#9679;</span> <?php echo esc_html( $label ); ?>
							<?php if ( $site->last_error ) : ?><br><span class="description cpub-site-error"><?php echo esc_html( $site->last_error ); ?></span><?php endif; ?></td>
						<td><?php echo esc_html( Sites::CONNECTED === $site->status ? self::when( $site->connected_at ) : '—' ); ?></td>
						<td><?php echo esc_html( self::when( $site->last_check_at ) ); ?></td>
						<td><?php echo esc_html( $site->remote_user ?: '—' ); ?></td>
						<?php if ( $manage ) : ?>
						<td>
							<?php
							if ( Sites::CONNECTED === $site->status ) {
								printf( '<a class="button" href="%s" data-action="cpub_site_settings">Settings</a> ', esc_url( SiteSettingsPage::url( (int) $site->id ) ) );
								self::form( 'cpub_site_check', (int) $site->id, 'Check now' );
								self::form( 'cpub_site_disconnect', (int) $site->id, 'Disconnect', 'button button-link-delete', 'Disconnect ' . ( $site->name ?: $site->url ) . '? Its access will be revoked; you\'ll need its administrator to approve again to reconnect.' );
							} else {
								printf(
									'<form method="post" action="%s" style="display:inline"><input type="hidden" name="action" value="cpub_connect"><input type="hidden" name="site_url" value="%s">%s<button type="submit" class="button" data-action="cpub_reconnect">%s</button></form> ',
									esc_url( admin_url( 'admin-post.php' ) ),
									esc_attr( $site->url ),
									wp_nonce_field( 'cpub_connect', '_wpnonce', true, false ),
									Sites::PENDING === $site->status ? 'Try again' : 'Reconnect'
								);
								self::form( 'cpub_site_remove', (int) $site->id, 'Remove', 'button button-link-delete', 'Remove ' . ( $site->name ?: $site->url ) . ' from the list?' );
							}
							?>
						</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
