<?php
/**
 * Which uploaded image an "[IMAGE: name]" line (in the Markdown: an image
 * whose address is just a file name) refers to.
 *
 * Matching ignores case and any folder part. If the names differ only in the
 * extension ("hero" or "hero.jpeg" for "hero.jpg"), the file matches when it
 * is the only one with that name.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ImageMatcher {

	private static function norm( string $name ): string {
		$name = str_replace( '\\', '/', rawurldecode( trim( $name ) ) );
		return mb_strtolower( basename( $name ) );
	}

	private static function stem( string $name ): string {
		$n   = self::norm( $name );
		$dot = strrpos( $n, '.' );
		return false === $dot ? $n : substr( $n, 0, $dot );
	}

	/**
	 * @param object[] $images image assets (filename)
	 */
	public static function find( string $ref, array $images ): ?object {
		if ( '' === trim( $ref ) || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $ref ) ) {
			return null; // a web address (or data:, javascript:…): not a file name
		}
		$n = self::norm( $ref );
		foreach ( $images as $img ) {
			if ( self::norm( (string) $img->filename ) === $n ) {
				return $img;
			}
		}
		$stem = self::stem( $ref );
		$hits = array_values( array_filter( $images, fn( $img ) => self::stem( (string) $img->filename ) === $stem ) );
		return 1 === count( $hits ) ? $hits[0] : null;
	}

	/**
	 * Names a text refers to with "[IMAGE: name]" lines or Markdown images, for
	 * sharing out images uploaded with several posts at once.
	 *
	 * @return string[]
	 */
	public static function references( string $text ): array {
		preg_match_all( '/^\s*\[\s*(?:image|img|photo|picture|figure)\s*:\s*([^\]\r\n]+?)\s*\]\s*$/imu', $text, $m1 );
		preg_match_all( '/!\[[^\]]*\]\(\s*<?([^)\s>]+)/u', $text, $m2 );
		return array_values( array_unique( array_merge( $m1[1], $m2[1] ) ) );
	}
}
