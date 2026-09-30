<?php
/**
 * Settings for one client site (default category), reached from Client sites.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\SiteSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteSettingsPage {

	public const SLUG   = 'cpub-publisher-site';
	public const ACTION = 'cpub_site_settings';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	public static function url( int $site_id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&site=' . $site_id );
	}

	public static function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( 'Only Content Publisher managers can change site settings.', 403 );
		}
		$site_id = (int) ( $_POST['site'] ?? 0 );
		check_admin_referer( self::ACTION . '_' . $site_id );
		$site = Sites::get( $site_id );
		if ( ! $site ) {
			wp_die( 'That client site no longer exists.', 404 );
		}
		$cat = (int) ( $_POST['default_category'] ?? 0 );
		$r   = SiteSettings::save( $site, $cat ?: null, ! empty( $_POST['use_post_category'] ), ! empty( $_POST['featured_image'] ), sanitize_key( (string) ( $_POST['send_as'] ?? 'draft' ) ) );
		Notices::add( is_wp_error( $r ) ? 'error' : 'success', is_wp_error( $r ) ? $r->get_error_message() : 'Settings saved.' );
		wp_safe_redirect( self::url( $site_id ) );
		if ( apply_filters( 'cpub_publisher_exit_after_redirect', true ) ) {
			exit;
		}
	}

	public function render(): void {
		$site = Sites::get( (int) ( $_GET['site'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wrap">';
		if ( ! $site ) {
			echo '<h1>Client site not found</h1></div>';
			return;
		}
		$s    = SiteSettings::get( $site );
		$cats = Sites::CONNECTED === $site->status ? SiteSettings::categories( $site ) : new \WP_Error( 'x', 'The site is not connected.' );
		?>
		<h1><?php echo esc_html( 'Settings for ' . ( $site->name ?: $site->url ) ); ?></h1>
		<?php Notices::render(); ?>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Menu::SLUG ) ); ?>">← Client sites</a></p>
		<p>These settings apply when a post is sent. <?php echo $site->remote_user ? esc_html( 'Posts appear on the site under ' . $site->remote_user . ' (chosen by the site when it approved the connection).' ) : ''; ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cpub-site-settings">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="site" value="<?php echo (int) $site->id; ?>">
			<?php wp_nonce_field( self::ACTION . '_' . (int) $site->id ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cpub-category">Default category</label></th>
					<td>
						<?php if ( is_wp_error( $cats ) ) : ?>
							<p class="notice notice-warning inline" style="padding:8px 12px"><?php echo esc_html( 'Couldn\'t read the site\'s categories: ' . $cats->get_error_message() ); ?></p>
							<p>Current: <?php echo esc_html( $s['default_category']['name'] ?? 'the site\'s own default' ); ?></p>
							<input type="hidden" name="default_category" value="<?php echo (int) ( $s['default_category']['id'] ?? 0 ); ?>">
						<?php else : ?>
							<select name="default_category" id="cpub-category">
								<option value="0">The site's own default category</option>
								<?php foreach ( $cats as $c ) : ?>
									<option value="<?php echo (int) $c['id']; ?>" <?php selected( (int) ( $s['default_category']['id'] ?? 0 ), $c['id'] ); ?>><?php echo esc_html( ( $c['parent'] ? '— ' : '' ) . $c['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Read live from <?php echo esc_html( $site->url ); ?>.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Category lines</th>
					<td><label><input type="checkbox" name="use_post_category" value="1" <?php checked( $s['use_post_category'] ); ?>> When a post has a “Category: …” line that names one of the site's categories, use that instead</label></td>
				</tr>
				<tr>
					<th scope="row">Send as</th>
					<td>
						<label><input type="radio" name="send_as" value="draft" <?php checked( $s['send_as'], 'draft' ); ?>> A draft, for the site's own editors to publish</label><br>
						<label><input type="radio" name="send_as" value="publish" <?php checked( $s['send_as'], 'publish' ); ?> id="cpub-send-as-publish"> Published straight away (or scheduled, if the post has a publish date)</label>
						<p class="description" id="cpub-can-publish" data-allowed="<?php echo empty( $site->can_publish ) ? '0' : '1'; ?>"><?php echo empty( $site->can_publish ) ? 'The site hasn\'t allowed direct publishing (its administrator can allow it under Settings > Content Publisher on their site), so posts go as drafts until it does.' : 'The site allows direct publishing.'; ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">Featured image</th>
					<td><label><input type="checkbox" name="featured_image" value="1" <?php checked( $s['featured_image'] ); ?>> Use the post's first image as its featured image</label>
						<p class="description">Leave this off if the site's theme shows the featured image above the post: the image would appear twice.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>
		</div>
		<?php
	}
}
