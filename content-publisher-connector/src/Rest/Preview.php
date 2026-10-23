<?php
/**
 * Signed preview links: the agency sees its unpublished post or page through
 * this site's own theme, without logging in.
 *
 * GET /cpub-connector/v1/preview/{id} (a token request) returns a link for one
 * of the agency's own drafts, posts waiting for review or scheduled posts. The
 * link shows that one post, for 30 minutes, and only while the connection that
 * asked for it is still active and the post is still the agency's and still
 * unpublished. The page is sent with noindex, no caching and no referrer, so the
 * link doesn't end up in search results, caches or other sites' logs.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Rest;

use CPub\Connector\ActivityLog;
use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\Scopes;
use CPub\Connector\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Preview {

	public const ROUTE     = '/preview/(?P<id>\d+)';
	public const QUERY_VAR = 'cpub_preview';
	public const TTL       = 1800; // 30 minutes

	/** What a preview is for: the agency's posts the public can't see yet. Never private. */
	public const STATUSES = array( 'draft', 'pending', 'future' );

	/** post type => the scope needed to preview it */
	private const TYPES = array(
		'post' => Scopes::POSTS_WRITE,
		'page' => Scopes::PAGES_WRITE,
	);

	private const SECRET_OPTION = 'cpub_connector_preview_secret';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'pre_get_posts', array( self::class, 'show' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'create' ),
				'permission_callback' => fn() => null !== BearerAuth::grant(),
			)
		);
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function create( \WP_REST_Request $request ) {
		$grant = BearerAuth::grant();
		$post  = get_post( (int) $request['id'] );
		if ( ! $post || ! isset( self::TYPES[ $post->post_type ] ) ) {
			return new \WP_Error( 'cpub_preview_not_found', 'There is no post or page with this ID.', array( 'status' => 404 ) );
		}
		if ( ! self::may_preview( $grant, $post ) ) {
			return new \WP_Error( 'cpub_preview_not_ours', 'Previews are only for the posts and pages this connection created.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $post->post_status, self::STATUSES, true ) ) {
			$live = 'publish' === $post->post_status;
			return new \WP_Error(
				'cpub_preview_not_needed',
				$live ? 'This is published: anyone can see it at its address.' : 'Only drafts, posts waiting for review and scheduled posts can be previewed.',
				array( 'status' => 409, 'link' => $live ? get_permalink( $post ) : null )
			);
		}
		$expires = time() + self::TTL;
		ActivityLog::event( (int) $grant->id, 'preview_link', sprintf( 'Preview link for "%s" (%s %d), valid until %s UTC.', $post->post_title, $post->post_type, $post->ID, gmdate( 'Y-m-d H:i', $expires ) ) );
		return new \WP_REST_Response(
			array(
				'id'         => $post->ID,
				'url'        => self::url( $post, (int) $grant->id, $expires ),
				'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
				'expires_in' => self::TTL,
			)
		);
	}

	/** The front-end address: the plain ?p= / ?page_id= link plus the signed token. */
	public static function url( \WP_Post $post, int $grant_id, int $expires ): string {
		$token = $post->ID . '.' . $grant_id . '.' . $expires . '.' . self::signature( $post->ID, $grant_id, $expires );
		return add_query_arg(
			array(
				'page' === $post->post_type ? 'page_id' : 'p' => $post->ID,
				self::QUERY_VAR                               => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Front end: a request with a preview token shows that one post, whatever its
	 * status, through the theme. An invalid or expired token gets an error page.
	 */
	public static function show( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		self::private_page();
		$token = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$post  = self::verify( $token, (int) ( $query->get( 'p' ) ?: $query->get( 'page_id' ) ) );
		if ( ! $post ) {
			wp_die( 'This preview link has expired or is no longer valid. Ask for a new one.', 'Preview link expired', array( 'response' => 403 ) );
		}
		// WordPress shows a post that isn't public when its status was asked for explicitly.
		$query->set( 'post_status', array( $post->post_status ) );
		add_filter( 'redirect_canonical', '__return_false' );
		add_filter( 'comments_open', '__return_false' );
		add_filter( 'pings_open', '__return_false' );
	}

	/** The post a token is for, if it is valid now for that post. */
	public static function verify( string $token, int $post_id ): ?\WP_Post {
		if ( $post_id <= 0 || ! preg_match( '/^(\d+)\.(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $m ) || (int) $m[1] !== $post_id ) {
			return null;
		}
		[ , , $grant_id, $expires, $signature ] = $m;
		$now = time();
		if ( (int) $expires < $now || (int) $expires > $now + self::TTL || ! hash_equals( self::signature( $post_id, (int) $grant_id, (int) $expires ), $signature ) ) {
			return null;
		}
		$grant = Grants::active();
		$post  = get_post( $post_id );
		if ( ! $grant || (int) $grant->id !== (int) $grant_id || ! $post || ! in_array( $post->post_status, self::STATUSES, true ) || ! self::may_preview( $grant, $post ) ) {
			return null;
		}
		return $post;
	}

	/** Sent with every preview page, valid or not. @return array<string,string> */
	public static function headers(): array {
		return array(
			'X-Robots-Tag'    => 'noindex, nofollow',
			'Referrer-Policy' => 'no-referrer',
		);
	}

	private static function private_page(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // the convention page-cache plugins follow
		}
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		foreach ( self::headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	private static function may_preview( object $grant, \WP_Post $post ): bool {
		return isset( self::TYPES[ $post->post_type ] )
			&& in_array( self::TYPES[ $post->post_type ], Grants::scopes( $grant ), true )
			&& Ownership::is_ours( $post->ID );
	}

	private static function signature( int $post_id, int $grant_id, int $expires ): string {
		return hash_hmac( 'sha256', 'preview|' . $post_id . '|' . $grant_id . '|' . $expires, self::secret() );
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

	public static function delete_secret(): void {
		delete_option( self::SECRET_OPTION );
	}
}
