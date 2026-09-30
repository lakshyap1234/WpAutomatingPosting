<?php
/**
 * Limits token requests to what the granted scopes allow, and to drafts
 * unless the site lets the agency publish its own posts.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Auth;

use CPub\Connector\ActivityLog;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\Scopes;
use CPub\Connector\Rest\Ownership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RouteGate {

	/** Statuses a token may set, and a post must have for a token to edit it. */
	public const EDITABLE_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	/** Also allowed when the site lets the agency publish (its own posts only). */
	public const LIVE_STATUSES = array( 'publish', 'future' );

	/**
	 * Fields a token may send when writing. Anything else (sticky, meta, template,
	 * password, author, comment settings...) is refused, because it could take
	 * effect once the site publishes the post, or reach other plugins' data.
	 */
	public const WRITABLE_FIELDS = array(
		// No 'slug': WordPress 7.1 core warns when a draft is created with one (reads ->id for ->ID).
		'posts' => array( 'title', 'content', 'excerpt', 'status', 'categories', 'tags', 'featured_media', 'date', 'date_gmt' ),
		'media' => array( 'title', 'caption', 'alt_text', 'description', 'post', 'force' ),
		'tags'  => array( 'name' ),
	);

	/** Request controls that aren't content. */
	private const CONTROL_PARAMS = array( 'context', '_fields', '_embed', '_envelope', '_locale', '_method', 'rest_route' );

	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'check_route' ), 5, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'log_request' ), 999, 3 );
		add_filter( 'rest_pre_insert_post', array( self::class, 'drafts_only' ), 10, 2 );
		add_filter( 'rest_prepare_user', array( self::class, 'name_only' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_handle_upload_prefilter', array( self::class, 'images_only' ) );
		add_filter( 'wp_handle_sideload_prefilter', array( self::class, 'images_only' ) );
	}

	/** @param mixed $result */
	public static function check_route( $result, $server, \WP_REST_Request $request ) {
		$grant = BearerAuth::grant();
		if ( ! $grant || null !== $result ) {
			return $result;
		}
		$method = $request->get_method();
		$route  = $request->get_route();
		if ( null === Scopes::permitting( $method, $route, BearerAuth::scopes() ) ) {
			return self::refuse( $grant, $request, 'cpub_forbidden', 'This connection is not allowed to use this part of the site.', 'Refused: not allowed for this connection' );
		}
		if ( '/wp/v2/users/me' === untrailingslashit( $route ) && 'edit' === $request->get_param( 'context' ) ) {
			return self::refuse( $grant, $request, 'cpub_forbidden', 'This connection can read only the name of the user it posts as.', 'Refused: account details' );
		}
		if ( 'GET' === $method ) {
			return $result;
		}

		$kind  = str_starts_with( $route, '/wp/v2/media' ) ? 'media' : ( str_starts_with( $route, '/wp/v2/tags' ) ? 'tags' : 'posts' );
		$extra = array_diff( self::sent_fields( $request ), self::WRITABLE_FIELDS[ $kind ], self::CONTROL_PARAMS );
		if ( $extra ) {
			$fields = implode( ', ', $extra );
			return self::refuse( $grant, $request, 'cpub_field_not_allowed', 'This connection may not set: ' . $fields . '.', 'Refused: fields not allowed (' . $fields . ')' );
		}
		$parent = (int) $request->get_param( 'post' );
		if ( 'media' === $kind && $parent && ! self::may_change( $grant, $parent ) ) {
			return self::refuse( $grant, $request, 'cpub_media_locked', 'Images can only be attached to posts that are still drafts.', 'Refused: attach image to a post that is not a draft' );
		}
		if ( 'media' === $kind && preg_match( '#^/wp/v2/media/(\d+)$#', $route, $m ) && self::media_in_live_use( (int) $m[1], Grants::can_publish( $grant ) ) ) {
			return self::refuse( $grant, $request, 'cpub_media_locked', 'This image is used by a post the site has published, so it can only be changed by the site\'s own editors.', 'Refused: image is in a published post' );
		}
		return $result;
	}

	/**
	 * Whether a token may change this post: drafts always; the agency's own
	 * published or scheduled posts when the site allows publishing. (Auth\Sandbox
	 * separately limits every change to posts the agency created.)
	 */
	public static function may_change( object $grant, int $post_id ): bool {
		$status = get_post_status( $post_id );
		if ( in_array( $status, self::EDITABLE_STATUSES, true ) ) {
			return true;
		}
		return Grants::can_publish( $grant ) && in_array( $status, self::LIVE_STATUSES, true ) && Ownership::is_ours( $post_id );
	}

	private static function refuse( object $grant, \WP_REST_Request $request, string $code, string $message, string $log ): \WP_Error {
		ActivityLog::api( (int) $grant->id, $request->get_method(), $request->get_route(), 403, $log );
		return new \WP_Error( $code, $message, array( 'status' => 403 ) );
	}

	/**
	 * Names of every field sent, from every source. get_params() makes WordPress
	 * parse the body now; for PUT/PATCH/DELETE it otherwise isn't parsed until
	 * after this check, which would let form-encoded fields past it.
	 *
	 * @return string[]
	 */
	private static function sent_fields( \WP_REST_Request $request ): array {
		return array_keys( array_merge( (array) $request->get_params(), (array) $request->get_file_params() ) );
	}

	/**
	 * Whether a published, scheduled or private post uses this image (featured,
	 * in its content, or as parent). With $except_ours, the agency's own live
	 * posts don't count (it may change those when the site lets it publish);
	 * any of the site's own posts using the image still does.
	 */
	public static function media_in_live_use( int $id, bool $except_ours = false ): bool {
		global $wpdb;
		$attachment = get_post( $id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return false;
		}
		$counts = static fn( int $post_id ) => ! ( $except_ours && Ownership::is_ours( $post_id ) );
		if ( $attachment->post_parent && ! in_array( get_post_status( $attachment->post_parent ), self::EDITABLE_STATUSES, true ) && $counts( (int) $attachment->post_parent ) ) {
			return true;
		}
		$live = "'publish','future','private'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$featured = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = '_thumbnail_id' AND m.meta_value = %s AND p.post_status IN ({$live})", (string) $id ) );
		foreach ( $featured as $post_id ) {
			if ( $counts( (int) $post_id ) ) {
				return true;
			}
		}
		$like = static fn( string $s ) => '%' . $wpdb->esc_like( $s ) . '%';
		$used = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status IN ({$live}) AND post_type NOT IN ('revision','attachment')
				 AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content REGEXP %s )",
				$like( 'wp-image-' . $id . '"' ),
				$like( 'wp-image-' . $id . ' ' ),
				$like( '"id":' . $id . ',' ),
				$like( '"id":' . $id . '}' ),
				$like( '"mediaId":' . $id . ',' ),
				$like( '"mediaId":' . $id . '}' ),
				'ids="([0-9, ]*,)? *' . $id . ' *(,[0-9, ]*)?"' // [gallery ids="..."]
			)
		);
		// phpcs:enable
		foreach ( $used as $post_id ) {
			if ( $counts( (int) $post_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The user a token signs in as may be a real person on the site: the agency
	 * sees only their id and display name, not their login name, email or links.
	 *
	 * @param \WP_REST_Response $response
	 */
	public static function name_only( $response, $user, $request ) {
		if ( ! BearerAuth::grant() || ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		$response->set_data( array( 'id' => (int) ( $data['id'] ?? $user->ID ), 'name' => (string) ( $data['name'] ?? $user->display_name ) ) );
		foreach ( array_keys( $response->get_links() ) as $rel ) {
			$response->remove_link( $rel );
		}
		return $response;
	}

	/** @param mixed $response */
	public static function log_request( $response, $handler, \WP_REST_Request $request ) {
		$grant = BearerAuth::grant();
		if ( $grant ) {
			$status = is_wp_error( $response ) ? (int) ( $response->get_error_data()['status'] ?? 500 ) : ( $response instanceof \WP_HTTP_Response ? $response->get_status() : 200 );
			$detail = is_wp_error( $response ) ? $response->get_error_message() : '';
			ActivityLog::api( (int) $grant->id, $request->get_method(), $request->get_route(), $status, $detail );
		}
		return $response;
	}

	/**
	 * Tokens create drafts. Only when the site allows publishing may they
	 * publish or schedule, and change or unpublish the posts they published.
	 * Never private, and never another post the site has published.
	 *
	 * @param \stdClass|\WP_Error $prepared
	 */
	public static function drafts_only( $prepared, \WP_REST_Request $request ) {
		$grant = BearerAuth::grant();
		if ( ! $grant || is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$featured = (int) $request->get_param( 'featured_media' );
		if ( $featured > 0 && ! Ownership::is_ours( $featured ) ) {
			return new \WP_Error( 'cpub_featured_not_ours', 'The featured image must be one of the images this connection uploaded.', array( 'status' => 403 ) );
		}
		if ( ! empty( $prepared->ID ) && ! self::may_change( $grant, (int) $prepared->ID ) ) {
			return new \WP_Error( 'cpub_post_locked', 'This post is no longer a draft on the site, so it can only be changed by the site\'s own editors.', array( 'status' => 403 ) );
		}
		$allowed = Grants::can_publish( $grant ) ? array_merge( self::EDITABLE_STATUSES, self::LIVE_STATUSES ) : self::EDITABLE_STATUSES;
		if ( isset( $prepared->post_status ) && ! in_array( $prepared->post_status, $allowed, true ) ) {
			return Grants::can_publish( $grant )
				? new \WP_Error( 'cpub_status_not_allowed', 'This connection can save posts as drafts, publish or schedule them, but not make them private.', array( 'status' => 403 ) )
				: new \WP_Error( 'cpub_drafts_only', 'This site hasn\'t allowed the agency to publish: posts can only be saved as drafts or pending review.', array( 'status' => 403 ) );
		}
		return $prepared;
	}

	/** Uploads through a token must really be images (checked by content, not by the name). */
	public static function images_only( array $file ): array {
		if ( ! BearerAuth::grant() || ! empty( $file['error'] ) ) {
			return $file;
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'] ?? '', $file['name'] ?? '' );
		if ( empty( $check['type'] ) || ! str_starts_with( $check['type'], 'image/' ) ) {
			$file['error'] = 'This connection can only upload images.';
		}
		return $file;
	}
}
