<?php
/**
 * Request helpers.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestContext {

	public static function ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
