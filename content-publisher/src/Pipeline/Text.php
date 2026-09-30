<?php
/**
 * String helpers that behave like the JavaScript prototype's, so the PHP port
 * produces the same output (JS trim and \s are Unicode-aware).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Text {

	/** JavaScript's \s, exactly (use inside a character class, with the /u flag). */
	public const WS = '\t\n\x{0B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

	/** JavaScript's String.prototype.trim(). */
	public static function trim( string $s ): string {
		// The lookbehind keeps this linear on long whitespace runs even without PCRE JIT.
		return self::must( preg_replace( '/^[' . self::WS . ']+|(?<![' . self::WS . '])[' . self::WS . ']+$/u', '', $s ) );
	}

	/** Collapse whitespace runs to one space and trim, like .replace(/\s+/g, ' ').trim(). */
	public static function squash( string $s ): string {
		return self::trim( self::must( preg_replace( '/[' . self::WS . ']+/u', ' ', $s ) ) );
	}

	/**
	 * A preg_* result, or an exception if PCRE failed (invalid UTF-8, backtrack
	 * or JIT limits). Never let a failure turn text into '' silently.
	 */
	public static function must( $result ): string {
		if ( null === $result || false === $result ) {
			throw new \RuntimeException( 'Text processing failed (' . preg_last_error_msg() . '). Check the file is plain text.' );
		}
		return (string) $result;
	}

	public static function escape_html( string $s ): string {
		return str_replace( array( '&', '<', '>', '"' ), array( '&amp;', '&lt;', '&gt;', '&quot;' ), $s );
	}

	/** First $n characters, for messages. */
	public static function head( string $s, int $n ): string {
		return mb_substr( $s, 0, $n );
	}

	public const LIST_MARKER = '/^(?:[-*•–]|[0-9]{1,3}[.)]|[a-zA-Z][.)])[' . self::WS . ']+/u';

	public static function strip_list_marker( string $text ): string {
		return (string) preg_replace( self::LIST_MARKER, '', $text, 1 );
	}
}
