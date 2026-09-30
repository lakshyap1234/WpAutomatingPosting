<?php
/**
 * What an access token can reach. Anything not listed here is refused for
 * token requests, even where the Author role would allow it.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scopes {

	public const POSTS_WRITE  = 'posts:write';
	public const MEDIA_WRITE  = 'media:write';
	public const TERMS_READ   = 'terms:read';
	public const TERMS_WRITE  = 'terms:write';
	public const ACCOUNT_READ = 'account:read';

	/** scope => plain-language description for the consent screen. */
	public const LABELS = array(
		self::POSTS_WRITE  => 'Create posts, and edit the posts it created (publishing only if you allow it below)',
		self::MEDIA_WRITE  => 'Upload images to the Media Library',
		self::TERMS_READ   => 'Read the list of categories and tags',
		self::TERMS_WRITE  => 'Add new tags (not change or delete existing ones)',
		self::ACCOUNT_READ => 'Check that the connection is working',
	);

	/**
	 * scope => list of [methods, route regex]. Routes are REST routes without
	 * the /wp-json prefix, e.g. "/wp/v2/posts/12".
	 */
	private const RULES = array(
		self::ACCOUNT_READ => array(
			array( array( 'GET' ), '#^/wp/v2/users/me$#' ),
			array( array( 'GET' ), '#^/cpub-connector/v1/connection$#' ),
		),
		self::POSTS_WRITE  => array(
			array( array( 'GET' ), '#^/wp/v2/posts(?:/\d+)?$#' ),
			array( array( 'POST', 'PUT', 'PATCH' ), '#^/wp/v2/posts(?:/\d+)?$#' ),
		),
		self::MEDIA_WRITE  => array(
			array( array( 'GET' ), '#^/wp/v2/media(?:/\d+)?$#' ),
			array( array( 'POST', 'PUT', 'PATCH' ), '#^/wp/v2/media(?:/\d+)?$#' ),
			array( array( 'DELETE' ), '#^/wp/v2/media/\d+$#' ),
		),
		self::TERMS_READ   => array(
			array( array( 'GET' ), '#^/wp/v2/(?:categories|tags)(?:/\d+)?$#' ),
		),
		self::TERMS_WRITE  => array(
			array( array( 'POST' ), '#^/wp/v2/tags$#' ),
		),
	);

	/** @return string[] */
	public static function all(): array {
		return array_keys( self::LABELS );
	}

	public static function exists( string $scope ): bool {
		return isset( self::LABELS[ $scope ] );
	}

	/**
	 * The scope that permits this request, or null if none of the granted ones does.
	 *
	 * @param string[] $granted
	 */
	public static function permitting( string $method, string $route, array $granted ): ?string {
		$method = strtoupper( $method );
		$route  = '/' . ltrim( untrailingslashit( $route ), '/' );
		foreach ( $granted as $scope ) {
			foreach ( self::RULES[ $scope ] ?? array() as [ $methods, $pattern ] ) {
				if ( in_array( $method, $methods, true ) && preg_match( $pattern, $route ) ) {
					return $scope;
				}
			}
		}
		return null;
	}
}
