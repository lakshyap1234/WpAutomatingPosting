<?php
/**
 * What a token request may do, whoever it signs in as.
 *
 * A connection can post as one of the site's own Authors or Editors. While a
 * request signed in with our token runs, that person's permissions are cut
 * down to the few the agency needs, and only for posts and images the agency
 * created. The same person using the site normally keeps all their rights.
 *
 * WordPress asks "can this user do X?" through current_user_can(), which runs
 * the user_has_cap and map_meta_cap filters. Code that checks a user's role
 * directly skips both, which is why administrators can never be chosen and
 * RouteGate still limits the routes and fields a token can reach.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Auth;

use CPub\Connector\OAuth\Grants;
use CPub\Connector\Rest\Ownership;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sandbox {

	/**
	 * Always available to a token (if the person has them). edit_published_posts
	 * lets the agency read its own published posts in full (the daily check);
	 * RouteGate refuses any change to them unless the site allows publishing.
	 */
	private const BASE = array( 'read', 'edit_posts', 'upload_files', 'delete_posts', 'edit_published_posts' );

	/** Only when the site allowed the agency to publish directly. */
	private const PUBLISHING = array( 'publish_posts' );

	/** Post-level checks WordPress makes per post: all limited to what the agency created. */
	private const PER_POST = array( 'edit_post', 'delete_post', 'publish_post', 'read_post', 'edit_page', 'delete_page', 'read_page' );

	/** Statuses anyone may list; asking for any other lists only the agency's own posts. */
	private const PUBLIC_STATUSES = array( 'publish', 'inherit' );

	public static function register(): void {
		// Last, so no other plugin can hand a token more.
		add_filter( 'user_has_cap', array( self::class, 'limit' ), PHP_INT_MAX, 4 );
		add_filter( 'map_meta_cap', array( self::class, 'ours_only' ), PHP_INT_MAX, 4 );
		add_filter( 'rest_post_query', array( self::class, 'own_listings' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_attachment_query', array( self::class, 'own_listings' ), PHP_INT_MAX, 2 );
	}

	/**
	 * Listings: WordPress hides others' drafts from the results, but counts
	 * them in X-WP-Total, so a search for words in someone's draft would show
	 * "1 found". For token requests, anything beyond published posts is
	 * listed from the agency's own posts only (the signature is checked per
	 * post when it is read).
	 *
	 * @param array $args WP_Query arguments
	 */
	public static function own_listings( $args, $request ) {
		if ( ! BearerAuth::grant() ) {
			return $args;
		}
		$statuses = array_filter( (array) ( $args['post_status'] ?? array() ) );
		if ( ! $statuses || array_diff( $statuses, self::PUBLIC_STATUSES ) ) {
			$meta   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
			$meta[] = array( 'key' => Ownership::META_KEY, 'value' => 'v1:', 'compare' => 'LIKE' );
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return $args;
	}

	/** The connection behind this request, if the user being checked is the one it signs in as. */
	private static function grant_for( int $user_id ): ?object {
		$grant = BearerAuth::grant();
		return $grant && (int) $grant->user_id === $user_id && $user_id > 0 ? $grant : null;
	}

	/**
	 * @param array<string,bool> $allcaps the person's real capabilities
	 * @param string[]           $caps    what this check needs
	 * @param array              $args    [0] the capability asked about
	 */
	public static function limit( $allcaps, $caps, $args, $user ) {
		$grant = $user instanceof \WP_User ? self::grant_for( (int) $user->ID ) : null;
		if ( ! $grant ) {
			return $allcaps;
		}
		$allowed = self::BASE;
		if ( Grants::can_publish( $grant ) ) {
			$allowed = array_merge( $allowed, self::PUBLISHING );
		}
		$out = array();
		foreach ( $allowed as $cap ) {
			if ( ! empty( $allcaps[ $cap ] ) ) {
				$out[ $cap ] = true;
			}
		}
		// Creating a tag needs only assign_post_tags (edit_posts) in WordPress's
		// REST API; RouteGate lets a token create tags (terms:write) and nothing else.
		return $out;
	}

	/**
	 * Per-post checks: only posts and images the agency created. Published
	 * posts anyone can read stay readable.
	 *
	 * @param string[] $caps
	 */
	public static function ours_only( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, self::PER_POST, true ) || ! self::grant_for( (int) $user_id ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( ! $post ) {
			return $caps;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'attachment' ), true ) ) {
			return array( 'do_not_allow' );
		}
		if ( Ownership::is_ours( $post->ID ) ) {
			return $caps;
		}
		if ( 'read_post' === $cap && is_post_publicly_viewable( $post ) ) {
			return $caps;
		}
		return array( 'do_not_allow' );
	}
}
