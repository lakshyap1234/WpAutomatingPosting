<?php
/**
 * Content Publisher > Settings: the AI that structures posts.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Settings\AiSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SettingsPage {

	public const SLUG   = 'cpub-publisher-settings';
	public const ACTION = 'cpub_save_ai';

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( 'Only Content Publisher managers can change settings.', 403 );
		}
		check_admin_referer( self::ACTION );
		$key    = isset( $_POST['remove_key'] ) ? '' : ( '' === trim( (string) wp_unslash( $_POST['api_key'] ?? '' ) ) ? null : (string) wp_unslash( $_POST['api_key'] ) );
		$result = AiSettings::save( sanitize_key( $_POST['provider'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ), $key );
		Notices::add( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : 'Settings saved.' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		if ( apply_filters( 'cpub_publisher_exit_after_redirect', true ) ) {
			exit;
		}
	}

	public function render(): void {
		$s = AiSettings::get();
		?>
		<div class="wrap">
			<h1>Content Publisher settings</h1>
			<?php Notices::render(); ?>
			<h2>AI for structuring posts</h2>
			<p>The AI only decides which lines are the title, headings, paragraphs, lists, tables and images. It never writes or changes the text, and every result is checked against the original.</p>
			<div class="notice notice-warning inline"><p><strong>Use a paid API key before real client content goes through.</strong> Free tiers may let the provider use submitted text to improve its products.</p></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cpub-ai-settings">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cpub-provider">Provider</label></th>
						<td><select name="provider" id="cpub-provider">
							<?php foreach ( AiSettings::PROVIDERS as $id => $p ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['provider'], $id ); ?>><?php echo esc_html( $p['label'] ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="cpub-model">Model</label></th>
						<td><input type="text" name="model" id="cpub-model" class="regular-text code" value="<?php echo esc_attr( $s['model'] ); ?>">
							<p class="description">Leave as is unless you know you need another. Defaults: <?php echo esc_html( implode( ', ', array_map( fn( $p ) => $p['model'], AiSettings::PROVIDERS ) ) ); ?>.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="cpub-key">API key</label></th>
						<td>
							<input type="password" name="api_key" id="cpub-key" class="regular-text code" autocomplete="off" placeholder="<?php echo esc_attr( $s['has_key'] ? 'Saved (ends in …' . $s['key_hint'] . '). Type a new one to replace it.' : 'Paste the key' ); ?>">
							<?php if ( $s['has_key'] ) : ?>
								<label style="margin-left:8px"><input type="checkbox" name="remove_key" value="1"> Remove the saved key</label>
							<?php endif; ?>
							<p class="description">Stored encrypted. It is never shown again, only its last four characters.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}
}
