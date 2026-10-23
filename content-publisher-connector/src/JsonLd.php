<?php
/**
 * Structured data (JSON-LD) for a post or page: the cpub_jsonld REST field.
 *
 * The agency sends schema.org JSON-LD (an FAQ, a product, breadcrumbs...) with a
 * post or page, or in a proposal. It is checked before anything is saved: valid
 * JSON, @context schema.org, a top-level @type from TYPES, 32 KB at most. It is
 * kept in protected post meta (the REST API's own meta field can't reach it), and
 * the Connector prints it in the page's <head> itself, encoded so that nothing in
 * it can close the <script> tag.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JsonLd {

	public const FIELD     = 'cpub_jsonld';
	public const META_KEY  = '_cpub_jsonld';
	public const MAX_BYTES = 32768;
	public const MAX_DEPTH = 12;
	public const MAX_ITEMS = 20;

	/** The post types it can be set on. */
	public const POST_TYPES = array( 'post', 'page' );

	/** Top-level types the agency may publish. Nested values (a Question in an FAQPage...) may be any schema.org-style type. */
	public const TYPES = array(
		'Article',
		'BlogPosting',
		'NewsArticle',
		'FAQPage',
		'HowTo',
		'Organization',
		'LocalBusiness',
		'Product',
		'Service',
		'BreadcrumbList',
		'WebPage',
		'Event',
		'Recipe',
		'VideoObject',
		'Person',
		'Review',
	);

	private const CONTEXTS = array( 'https://schema.org', 'https://schema.org/', 'http://schema.org', 'http://schema.org/' );

	/** How it is printed: < and > as < and >, so "</script>" can't end the tag. */
	private const OUTPUT_FLAGS = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES;

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_field' ) );
		add_action( 'wp_head', array( self::class, 'print_head' ), 20 );
	}

	public static function register_field(): void {
		register_rest_field(
			self::POST_TYPES,
			self::FIELD,
			array(
				'get_callback'    => fn( $item ) => self::get( (int) $item['id'] ),
				'update_callback' => array( self::class, 'update' ),
				'schema'          => array(
					'description' => 'Structured data (schema.org JSON-LD) printed in the page head. null removes it.',
					'type'        => array( 'object', 'array', 'string', 'null' ),
					'context'     => array( 'edit' ),
					// Checked before the post is saved, so a bad value creates nothing.
					'arg_options' => array(
						'validate_callback' => array( self::class, 'check_arg' ),
						'sanitize_callback' => fn( $value ) => $value,
					),
				),
			)
		);
	}

	/** @return true|\WP_Error */
	public static function check_arg( $value ) {
		if ( self::is_removal( $value ) ) {
			return true;
		}
		$data = self::validate( $value );
		return is_wp_error( $data ) ? $data : true;
	}

	/** @return true|\WP_Error */
	public static function update( $value, $post ) {
		$post_id = $post instanceof \WP_Post ? $post->ID : (int) ( $post->ID ?? 0 );
		if ( self::is_removal( $value ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return true;
		}
		$data = self::validate( $value );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		self::save( $post_id, $data );
		return true;
	}

	/** null or an empty string: remove it. */
	public static function is_removal( $value ): bool {
		return null === $value || '' === $value;
	}

	/**
	 * The JSON-LD as PHP data, if it is acceptable.
	 *
	 * @param mixed $value a JSON string, or the decoded value (a JSON body)
	 * @return array|\WP_Error
	 */
	public static function validate( $value ) {
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > self::MAX_BYTES ) {
				return self::error( 'is larger than 32 KB.' );
			}
			$value = json_decode( $value, true, self::MAX_DEPTH + 2 );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return self::error( 'is not valid JSON (' . json_last_error_msg() . ').' );
			}
		}
		if ( ! is_array( $value ) || ! $value ) {
			return self::error( 'must be a JSON-LD object, or a list of them.' );
		}
		$items = array_is_list( $value ) ? $value : array( $value );
		if ( count( $items ) > self::MAX_ITEMS ) {
			return self::error( 'may have ' . self::MAX_ITEMS . ' items at most.' );
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! $item || array_is_list( $item ) ) {
				return self::error( 'must be a JSON-LD object, or a list of them.' );
			}
			if ( ! in_array( $item['@context'] ?? null, self::CONTEXTS, true ) ) {
				return self::error( 'needs "@context": "https://schema.org".' );
			}
			// First: every @type below is a plain type name (so the allowlist check can compare strings).
			$problem = self::check_nested( $item, 0, true );
			if ( null !== $problem ) {
				return self::error( $problem );
			}
			$tops = isset( $item['@graph'] ) ? $item['@graph'] : array( $item );
			if ( ! is_array( $tops ) || ! $tops || ! array_is_list( $tops ) || count( $tops ) > self::MAX_ITEMS ) {
				return self::error( '"@graph" must be a list of up to ' . self::MAX_ITEMS . ' objects.' );
			}
			foreach ( $tops as $top ) {
				$types = is_array( $top ) ? (array) ( $top['@type'] ?? array() ) : array();
				if ( ! $types || array_diff( $types, self::TYPES ) ) {
					return self::error( 'has a "@type" that isn\'t allowed. Allowed: ' . implode( ', ', self::TYPES ) . '.' );
				}
			}
		}
		if ( strlen( self::encode( $value ) ) > self::MAX_BYTES ) {
			return self::error( 'is larger than 32 KB.' );
		}
		return $value;
	}

	/** A problem below the top level, or null. Only the top level sets @context; every @type is a type name. */
	private static function check_nested( array $node, int $depth, bool $top ): ?string {
		if ( $depth > self::MAX_DEPTH ) {
			return 'is nested too deeply.';
		}
		foreach ( $node as $key => $value ) {
			if ( '@context' === $key && ! $top ) {
				return 'may set "@context" only at the top.';
			}
			if ( '@type' === $key ) {
				foreach ( (array) $value as $type ) {
					if ( ! is_string( $type ) || ! preg_match( '/^[A-Z][A-Za-z0-9]{0,63}$/', $type ) ) {
						return 'has a "@type" that isn\'t a schema.org type name.';
					}
				}
			}
			if ( is_array( $value ) ) {
				// The items of a top-level @graph are top-level too.
				$problem = self::check_nested( $value, $depth + 1, $top && '@graph' === $key );
				if ( null !== $problem ) {
					return $problem;
				}
			}
		}
		return null;
	}

	private static function error( string $problem ): \WP_Error {
		return new \WP_Error( 'cpub_jsonld_invalid', 'The structured data (JSON-LD) ' . $problem, array( 'status' => 400 ) );
	}

	/** The post's JSON-LD, checked again, or null. */
	public static function get( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return null;
		}
		$raw = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( '' === $raw ) {
			return null;
		}
		$data = self::validate( $raw );
		return is_wp_error( $data ) ? null : $data;
	}

	/** @param array|null $data from validate(); null removes it */
	public static function save( int $post_id, ?array $data ): void {
		if ( null === $data ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}
		update_post_meta( $post_id, self::META_KEY, wp_slash( self::store( $data ) ) );
	}

	/** How it is kept (and shown to editors): plain JSON. */
	public static function store( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	public static function pretty( array $data ): string {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** For the page head. */
	public static function encode( array $data ): string {
		return (string) wp_json_encode( $data, self::OUTPUT_FLAGS );
	}

	public static function print_head(): void {
		if ( ! is_singular( self::POST_TYPES ) ) {
			return;
		}
		$data = self::get( (int) get_queried_object_id() );
		if ( null !== $data ) {
			echo '<script type="application/ld+json">' . self::encode( $data ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON_HEX_TAG: no < or > in it
		}
	}
}
