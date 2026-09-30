<?php
/**
 * Status page: environment checks with what to do about each problem.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

use CPub\Publisher\Crypto\Key;
use CPub\Publisher\Status\Checks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StatusPage {

	private const ICONS = array(
		Checks::OK    => array( 'yes-alt', '#00a32a', 'OK' ),
		Checks::WARN  => array( 'warning', '#dba617', 'Warning' ),
		Checks::ERROR => array( 'dismiss', '#d63638', 'Problem' ),
	);

	public function render(): void {
		$checks = Checks::run();
		?>
		<div class="wrap">
			<h1>Content Publisher: status</h1>
			<p>Version <?php echo esc_html( CPUB_PUBLISHER_VERSION ); ?> · PHP <?php echo esc_html( PHP_VERSION ); ?> · WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?></p>
			<table class="widefat striped" style="max-width:960px" id="cpub-status">
				<thead><tr><th style="width:230px">Check</th><th style="width:100px">Result</th><th>Details</th></tr></thead>
				<tbody>
				<?php foreach ( $checks as $check ) : ?>
					<?php [ $icon, $color, $word ] = self::ICONS[ $check['status'] ]; ?>
					<tr data-check="<?php echo esc_attr( $check['id'] ); ?>" data-status="<?php echo esc_attr( $check['status'] ); ?>">
						<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
						<td><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="color:<?php echo esc_attr( $color ); ?>"></span> <?php echo esc_html( $word ); ?></td>
						<td>
							<?php echo esc_html( $check['detail'] ); ?>
							<?php if ( 'encryption_key' === $check['id'] && Checks::OK !== $check['status'] ) : ?>
								<p>Add this line to <code>wp-config.php</code>, above <code>/* That's all, stop editing! */</code>. It was generated just now and is not stored anywhere, so copy it before leaving this page. Keep a copy somewhere safe: if it's lost, connected client sites have to be reconnected.</p>
								<p><input type="text" readonly class="large-text code" onclick="this.select()" value="<?php echo esc_attr( Key::suggested_config_line() ); ?>"></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
