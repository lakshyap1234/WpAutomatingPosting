<?php
/**
 * WordPress.com, through which the agency reaches client sites running Jetpack.
 *
 * A client site runs Jetpack, connected to WordPress.com. The agency registers
 * one app on WordPress.com (developer.wordpress.com/apps) and puts its client ID
 * and secret in wp-config.php. Connecting a site sends one of its users to
 * WordPress.com's approval screen. The token that comes back works for that one
 * site, as that user, with that user's rights there, through WordPress.com's
 * API (which Jetpack relays to the site). It doesn't expire; the client can
 * revoke it on WordPress.com or by disconnecting Jetpack.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Wpcom {

	public const API       = 'https://public-api.wordpress.com';
	public const AUTHORIZE = self::API . '/oauth2/authorize';
	public const TOKEN     = self::API . '/oauth2/token';

	/** wp-config.php constants with the agency's WordPress.com app. */
	public const CLIENT_ID     = 'CPUB_WPCOM_CLIENT_ID';
	public const CLIENT_SECRET = 'CPUB_WPCOM_CLIENT_SECRET';

	public const REGISTER_URL = 'https://developer.wordpress.com/apps/';
	/** Where the person who approved can see and revoke the connection. */
	public const APPS_URL = 'https://wordpress.com/me/security/connected-applications';

	/** @return array{client_id:string, client_secret:string} */
	private static function app(): array {
		$app = array(
			'client_id'     => defined( self::CLIENT_ID ) ? (string) constant( self::CLIENT_ID ) : '',
			'client_secret' => defined( self::CLIENT_SECRET ) ? (string) constant( self::CLIENT_SECRET ) : '',
		);
		/**
		 * Filters the agency's WordPress.com app. For tests and development only:
		 * on a live site, set the two constants in wp-config.php.
		 *
		 * @param array{client_id:string, client_secret:string} $app
		 */
		$app = (array) apply_filters( 'cpub_publisher_wpcom_app', $app );
		return array(
			'client_id'     => trim( (string) ( $app['client_id'] ?? '' ) ),
			'client_secret' => trim( (string) ( $app['client_secret'] ?? '' ) ),
		);
	}

	public static function client_id(): string {
		return self::app()['client_id'];
	}

	public static function client_secret(): string {
		return self::app()['client_secret'];
	}

	public static function configured(): bool {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/**
	 * A route on a connected site, as WordPress.com serves it:
	 *
	 *   /wp/v2/posts          https://public-api.wordpress.com/wp/v2/sites/{id}/posts
	 *   /rest/v1.1/media/new  https://public-api.wordpress.com/rest/v1.1/sites/{id}/media/new
	 *   /rest/v1.1            https://public-api.wordpress.com/rest/v1.1/sites/{id}
	 *   /rest/v1.1/me         https://public-api.wordpress.com/rest/v1.1/me (the person who approved)
	 */
	public static function url( int $site_id, string $route, array $query = array() ): string {
		if ( '/rest/v1.1/me' === $route ) {
			$url = self::API . $route;
		} elseif ( preg_match( '#^/(wp/v2|rest/v1\.1)(/.*)?$#', $route, $m ) ) {
			$url = self::API . '/' . $m[1] . '/sites/' . $site_id . ( $m[2] ?? '' );
		} else {
			throw new \InvalidArgumentException( esc_html( "Not a WordPress.com route: $route" ) );
		}
		return $query ? add_query_arg( array_map( 'rawurlencode', $query ), $url ) : $url;
	}

	/**
	 * What WordPress.com knows about a site address, asked without logging in:
	 * its ID and name. Only sites connected through Jetpack (or hosted on
	 * WordPress.com) are known there.
	 *
	 * @return array{id:int, name:string, url:string, jetpack:bool}|\WP_Error
	 */
	public static function lookup( string $site_url ) {
		// "client.com/blog" is "client.com::blog" in WordPress.com's site addresses.
		$slug   = str_replace( '%3A', ':', rawurlencode( str_replace( '/', '::', (string) preg_replace( '#^https://#', '', $site_url ) ) ) );
		$result = Http::request( 'GET', self::API . '/rest/v1.1/sites/' . $slug . '?fields=ID,name,URL,jetpack' );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'cpub_unreachable', 'Couldn\'t reach WordPress.com: ' . $result->get_error_message() );
		}
		$data = is_array( $result['data'] ) ? $result['data'] : array();
		if ( 200 === $result['status'] && (int) ( $data['ID'] ?? 0 ) > 0 ) {
			return array(
				'id'      => (int) $data['ID'],
				'name'    => wp_strip_all_tags( html_entity_decode( (string) ( $data['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ),
				'url'     => (string) ( $data['URL'] ?? '' ),
				'jetpack' => ! empty( $data['jetpack'] ),
			);
		}
		if ( 404 === $result['status'] || 'unknown_blog' === ( $data['error'] ?? '' ) ) {
			return new \WP_Error( 'cpub_no_jetpack', sprintf( 'WordPress.com doesn\'t know %s. The site needs Jetpack installed and connected to WordPress.com (Jetpack > Dashboard on that site), then try again.', $site_url ) );
		}
		if ( 403 === $result['status'] ) {
			return new \WP_Error( 'cpub_no_jetpack', sprintf( 'WordPress.com won\'t share %s\'s details (%s). If the site is private, or Jetpack\'s JSON API is turned off there, ask its administrator to allow it, then try again.', $site_url, Http::describe( $result ) ) );
		}
		return new \WP_Error( 'cpub_unreachable', sprintf( 'Couldn\'t look up %s on WordPress.com (%s).', $site_url, Http::describe( $result ) ) );
	}

	/**
	 * The approving person's role on the site, from the capabilities WordPress.com
	 * reports for them there ('' if they can't write posts).
	 *
	 * @param array<string,bool> $caps
	 */
	public static function role( array $caps ): string {
		return match ( true ) {
			! empty( $caps['manage_options'] )    => 'administrator',
			! empty( $caps['edit_others_posts'] ) => 'editor',
			! empty( $caps['publish_posts'] )     => 'author',
			! empty( $caps['edit_posts'] )        => 'contributor',
			default                               => '',
		};
	}
}
