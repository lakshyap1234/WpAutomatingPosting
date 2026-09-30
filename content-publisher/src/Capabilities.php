<?php
/**
 * Custom capabilities.
 *
 * cpub_manage: connect client sites, change settings (administrators).
 * cpub_review: upload content, review and send posts (administrators and editors).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {

	public const MANAGE = 'cpub_manage';
	public const REVIEW = 'cpub_review';

	/** role => capabilities granted on activation. */
	public const DEFAULTS = array(
		'administrator' => array( self::MANAGE, self::REVIEW ),
		'editor'        => array( self::REVIEW ),
	);

	public static function grant(): void {
		foreach ( self::DEFAULTS as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/** Removes our capabilities from every role, including ones granted by hand later. */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::REVIEW );
		}
	}
}
