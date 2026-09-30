<?php
/**
 * Marks every post and image the agency creates, so a connection can be
 * limited to its own work even when it posts as one of the site's users.
 *
 * The mark is signed with a secret kept on this site and bound to the post's
 * ID, so a copy of the post (a "duplicate post" plugin copies all meta) is not
 * the agency's. It is written inside WordPress's own insert (a placeholder via
 * meta_input, signed on the insert hook), so a request that dies later still
 * leaves a marked post. The key starts with an underscore, so the REST API
 * never lets anyone set it.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Rest;

use CPub\Connector\Auth\BearerAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ownership {

	/** Post meta: "v1:<grant id>:<signature>". */
	public const META_KEY = '_cpub_agency_post';

	private const SECRET_OPTION = 'cpub_connector_mark_secret';
	private const PENDING       = 'pending';

	public static function register(): void {
		add_filter( 'rest_pre_insert_post', array( self::class, 'mark' ), 5, 2 );
		add_filter( 'rest_pre_insert_attachment', array( self::class, 'mark' ), 5, 2 );
		add_action( 'wp_insert_post', array( self::class, 'sign_post' ), 1, 3 );
		add_action( 'add_attachment', array( self::class, 'sign' ), 1 );
	}

	/**
	 * @param \stdClass|\WP_Error $prepared
	 * @return \stdClass|\WP_Error
	 */
	public static function mark( $prepared, \WP_REST_Request $request ) {
		$grant = BearerAuth::grant();
		if ( ! $grant || ! $prepared instanceof \stdClass || ! empty( $prepared->ID ) ) {
			return $prepared;
		}
		$meta                   = isset( $prepared->meta_input ) && is_array( $prepared->meta_input ) ? $prepared->meta_input : array();
		$meta[ self::META_KEY ] = self::PENDING . ':' . (int) $grant->id;
		$prepared->meta_input   = $meta;
		return $prepared;
	}

	/** @param \WP_Post $post */
	public static function sign_post( $post_id, $post, $update ): void {
		if ( ! $update ) {
			self::sign( (int) $post_id );
		}
	}

	/** Replace the placeholder written moments ago in this insert with the signed mark. */
	public static function sign( $post_id ): void {
		$grant = BearerAuth::grant();
		$value = (string) get_post_meta( (int) $post_id, self::META_KEY, true );
		if ( $grant && self::PENDING . ':' . (int) $grant->id === $value ) {
			update_post_meta( (int) $post_id, self::META_KEY, self::value( (int) $post_id, (int) $grant->id ) );
		}
	}

	public static function is_ours( int $post_id ): bool {
		$value = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( ! preg_match( '/^v1:(\d+):([a-f0-9]{64})$/', $value, $m ) ) {
			return false;
		}
		return hash_equals( self::signature( $post_id, (int) $m[1] ), $m[2] );
	}

	private static function value( int $post_id, int $grant_id ): string {
		return 'v1:' . $grant_id . ':' . self::signature( $post_id, $grant_id );
	}

	private static function signature( int $post_id, int $grant_id ): string {
		return hash_hmac( 'sha256', $post_id . '|' . $grant_id, self::secret() );
	}

	private static function secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( strlen( $secret ) < 64 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			add_option( self::SECRET_OPTION, $secret, '', false );
			$secret = (string) get_option( self::SECRET_OPTION, $secret ); // another request may have won
		}
		return $secret;
	}

	/**
	 * Upgrading from 0.4.0: everything the Agency Publisher user created was
	 * the agency's. Marks it once.
	 */
	public static function mark_legacy( int $user_id ): int {
		global $wpdb;
		if ( $user_id <= 0 ) {
			return 0;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ('post','attachment')", $user_id ) );
		// phpcs:enable
		$n = 0;
		foreach ( $ids as $id ) {
			if ( ! self::is_ours( (int) $id ) ) {
				update_post_meta( (int) $id, self::META_KEY, self::value( (int) $id, 0 ) );
				++$n;
			}
		}
		return $n;
	}
}
