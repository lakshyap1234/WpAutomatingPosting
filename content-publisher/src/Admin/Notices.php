<?php
/**
 * One-off messages shown after an action redirects back to a page.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notices {

	private static function key(): string {
		return 'cpub_notice_' . get_current_user_id();
	}

	public static function add( string $type, string $message ): void {
		$all   = get_transient( self::key() ) ?: array();
		$all[] = array( 'type' => $type, 'message' => $message );
		set_transient( self::key(), $all, 5 * MINUTE_IN_SECONDS );
	}

	public static function render(): void {
		$all = get_transient( self::key() );
		if ( ! $all ) {
			return;
		}
		delete_transient( self::key() );
		foreach ( $all as $n ) {
			printf( '<div class="notice notice-%s is-dismissible cpub-notice" data-type="%s"><p>%s</p></div>', esc_attr( $n['type'] ), esc_attr( $n['type'] ), esc_html( $n['message'] ) );
		}
	}
}
