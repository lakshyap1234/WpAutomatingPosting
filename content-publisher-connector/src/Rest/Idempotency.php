<?php
/**
 * Duplicate protection for the agency's uploads and new drafts.
 *
 * The agency sends a one-time key with each new post or image
 * (X-CPub-Idempotency-Key). The key is saved with what it created. If the same
 * key arrives again (the agency never got the first reply and retried), the
 * existing post or image is returned instead of a second one being created.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Rest;

use CPub\Connector\Auth\BearerAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Idempotency {

	public const HEADER   = 'x-cpub-idempotency-key';
	public const META_KEY = '_cpub_idempotency_key';

	/** route => post type */
	private const ROUTES = array(
		'/wp/v2/posts' => 'post',
		'/wp/v2/media' => 'attachment',
	);

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'reuse' ), 20, 3 ); // after RouteGate's checks (priority 5)
		// The key goes in with the insert itself (meta_input), so a failure later in the
		// request (terms, featured image) can't leave a keyless post that a retry would duplicate.
		add_filter( 'rest_pre_insert_post', array( self::class, 'attach' ), 10, 2 );
		add_filter( 'rest_pre_insert_attachment', array( self::class, 'attach' ), 10, 2 );
		// Fallback in case another plugin's filter rebuilt the prepared object.
		add_action( 'rest_after_insert_post', array( self::class, 'remember' ), 10, 3 );
		add_action( 'rest_after_insert_attachment', array( self::class, 'remember' ), 10, 3 );
	}

	/** The key on a token request that creates something, or null. */
	private static function key( \WP_REST_Request $request ): ?string {
		if ( ! BearerAuth::grant() || 'POST' !== $request->get_method() || ! isset( self::ROUTES[ $request->get_route() ] ) ) {
			return null;
		}
		$key = (string) $request->get_header( self::HEADER );
		return '' === $key ? null : $key;
	}

	/** @param mixed $result */
	public static function reuse( $result, $server, \WP_REST_Request $request ) {
		if ( null !== $result ) {
			return $result;
		}
		$key = self::key( $request );
		if ( null === $key ) {
			return $result;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $key ) ) {
			return new \WP_Error( 'cpub_bad_key', 'The duplicate-protection key is malformed.', array( 'status' => 400 ) );
		}
		$existing = self::find( $key, self::ROUTES[ $request->get_route() ] );
		if ( ! $existing ) {
			return $result; // create it; remember() saves the key
		}
		$get = new \WP_REST_Request( 'GET', $request->get_route() . '/' . $existing );
		$get->set_param( 'context', 'edit' );
		$response = rest_do_request( $get );
		if ( $response->is_error() ) {
			return $response;
		}
		$response->set_status( 200 );
		$response->header( 'X-CPub-Existing', '1' );
		return $response;
	}

	/** Something this connection's user created earlier with the key (in any state, even the bin). */
	private static function find( string $key, string $type ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'any', 'trash', 'auto-draft', 'inherit' ),
				'author'           => get_current_user_id(),
				'meta_key'         => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'           => 'ids',
				'numberposts'      => 5,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
		// Only a post the agency created: a copy (a "duplicate post" plugin copies
		// the key too) is someone else's.
		foreach ( $ids as $id ) {
			if ( Ownership::is_ours( (int) $id ) ) {
				return (int) $id;
			}
		}
		return null;
	}

	/**
	 * @param \stdClass|\WP_Error $prepared
	 * @return \stdClass|\WP_Error
	 */
	public static function attach( $prepared, \WP_REST_Request $request ) {
		$key = self::key( $request );
		if ( ! $prepared instanceof \stdClass || ! empty( $prepared->ID ) || null === $key || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $key ) ) {
			return $prepared;
		}
		$meta                        = isset( $prepared->meta_input ) && is_array( $prepared->meta_input ) ? $prepared->meta_input : array();
		$meta[ self::META_KEY ]      = $key;
		$prepared->meta_input        = $meta;
		return $prepared;
	}

	public static function remember( $post, \WP_REST_Request $request, bool $creating ): void {
		$key = self::key( $request );
		if ( $creating && null !== $key && preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $key ) ) {
			update_post_meta( (int) $post->ID, self::META_KEY, $key );
		}
	}
}
