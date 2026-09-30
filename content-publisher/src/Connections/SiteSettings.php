<?php
/**
 * Per-site settings (the sites.settings column, JSON).
 *
 *   default_category  {id, name} on the client's site, or null for the site's own default
 *   use_post_category whether a "Category: …" line in the post overrides it
 *   featured_image    whether the post's first image becomes its featured image
 *   send_as           'draft' (for the site's editors to publish) or 'publish'
 *                     (published or scheduled straight away; only works if the
 *                     site allowed it on its Connector, see Sites.can_publish)
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Connections;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteSettings {

	public const DEFAULTS = array(
		'default_category'  => null,
		'use_post_category' => true,
		'featured_image'    => false,
		'send_as'           => 'draft',
	);

	public const SEND_AS = array( 'draft', 'publish' );

	/** @return array{default_category: ?array{id:int, name:string}, use_post_category: bool, featured_image: bool, send_as: string} */
	public static function get( object $site ): array {
		$s   = json_decode( (string) ( $site->settings ?? '' ), true );
		$s   = is_array( $s ) ? $s : array();
		$cat = $s['default_category'] ?? null;
		return array(
			'default_category'  => is_array( $cat ) && ! empty( $cat['id'] ) ? array( 'id' => (int) $cat['id'], 'name' => (string) ( $cat['name'] ?? '' ) ) : null,
			'use_post_category' => (bool) ( $s['use_post_category'] ?? self::DEFAULTS['use_post_category'] ),
			'featured_image'    => (bool) ( $s['featured_image'] ?? self::DEFAULTS['featured_image'] ),
			'send_as'           => in_array( $s['send_as'] ?? '', self::SEND_AS, true ) ? $s['send_as'] : self::DEFAULTS['send_as'],
		);
	}

	/**
	 * @param ?int $category_id a category on the client's site (checked against its list), or null
	 * @return true|\WP_Error
	 */
	public static function save( object $site, ?int $category_id, bool $use_post_category, bool $featured_image = false, string $send_as = 'draft' ) {
		$send_as = in_array( $send_as, self::SEND_AS, true ) ? $send_as : 'draft';
		$cat = null;
		if ( $category_id ) {
			$list = self::categories( $site );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			foreach ( $list as $c ) {
				if ( $c['id'] === $category_id ) {
					$cat = $c;
				}
			}
			if ( ! $cat ) {
				return new \WP_Error( 'cpub_category', 'That category doesn\'t exist on the client\'s site any more. Reload the page and choose again.' );
			}
			$cat = array( 'id' => $cat['id'], 'name' => $cat['name'] );
		}
		Sites::update( (int) $site->id, array( 'settings' => wp_json_encode( array( 'default_category' => $cat, 'use_post_category' => $use_post_category, 'featured_image' => $featured_image, 'send_as' => $send_as ) ) ) );
		\CPub\Publisher\Support\Events::log( 'site_settings', 'Settings changed: default category ' . ( $cat ? '“' . $cat['name'] . '”' : 'the site\'s own' ) . ( $use_post_category ? ', Category: lines in posts override it' : '' ) . '; posts are ' . ( 'publish' === $send_as ? 'published straight away (when the site allows it).' : 'sent as drafts.' ), (int) $site->id );
		return true;
	}

	/**
	 * The client site's categories, read live through the Connector (terms:read).
	 *
	 * @return array<int, array{id:int, name:string, parent:int}>|\WP_Error
	 */
	public static function categories( object $site ) {
		$out = array();
		for ( $page = 1; $page <= 5; $page++ ) {
			$r = ConnectorClient::request( $site, 'GET', '/wp/v2/categories', array( 'query' => array( 'per_page' => 100, 'page' => $page, '_fields' => 'id,name,parent', 'orderby' => 'name', 'order' => 'asc' ) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( 200 !== $r['status'] || ! is_array( $r['data'] ) ) {
				return 400 === $r['status'] && $page > 1 ? $out : new \WP_Error( 'cpub_categories', sprintf( 'Couldn\'t read the categories from %s (HTTP %d).', $site->name ?: $site->url, $r['status'] ) );
			}
			foreach ( $r['data'] as $c ) {
				if ( is_array( $c ) && isset( $c['id'] ) ) {
					$out[] = array( 'id' => (int) $c['id'], 'name' => html_entity_decode( (string) ( $c['name'] ?? '' ), ENT_QUOTES, 'UTF-8' ), 'parent' => (int) ( $c['parent'] ?? 0 ) );
				}
			}
			if ( count( $r['data'] ) < 100 ) {
				break;
			}
		}
		return $out;
	}
}
