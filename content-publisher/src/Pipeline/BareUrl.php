<?php
/**
 * Finds bare web addresses in text: the ones the Markdown writer leaves
 * unescaped and the converter turns into links. One detector for both, so
 * they can never disagree (if they did, an address containing * or _ could
 * be read as emphasis and the text would change).
 *
 * Rules follow linkify-it (what the prototype used) where they matter:
 * - starts with http://, https:// or www., not in the middle of a word;
 * - needs a real host name; www. addresses need a known top-level domain;
 * - the path stops at spaces, < > " ' |, and trailing punctuation or
 *   unbalanced closing brackets are left out.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BareUrl {

	/** linkify-it's default list: common generic TLDs plus real two-letter country codes. */
	private const TLDS = 'biz|com|edu|gov|net|org|pro|web|xxx|aero|asia|coop|info|museum|name|shop|рф|a[cdefgilmnoqrstuwxz]|b[abdefghijmnorstvwyz]|c[acdfghiklmnoruvwxyz]|d[ejkmoz]|e[cegrstu]|f[ijkmor]|g[abdefghilmnpqrstuwy]|h[kmnrtu]|i[delmnoqrst]|j[emop]|k[eghimnprwyz]|l[abcikrstuvy]|m[acdeghklmnopqrstuvwxyz]|n[acefgilopruz]|o[m]|p[aefghklmnrstwy]|q[a]|r[eosuw]|s[abcdeghijklmnortuvxyz]|t[cdfghjklmnortvwz]|u[agksyz]|v[aceginu]|w[fs]|y[et]|z[amw]';

	private const LABEL = '[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?';

	/**
	 * The address at the very start of $s, or null.
	 */
	public static function match_at( string $s ): ?string {
		if ( ! preg_match( '/^(https?:\/\/|www\.)/i', $s, $p ) ) {
			return null;
		}
		$prefix = $p[1];
		// Only the start matters; a cap keeps scanning linear on huge lines (writer and parser cut alike).
		$rest = mb_strcut( $s, strlen( $prefix ), 8192 );
		$label  = self::LABEL;
		if ( ! preg_match( "/^(?:localhost|\\d{1,3}(?:\\.\\d{1,3}){3}|{$label}(?:\\.{$label})*)(?::\\d{1,5})?/iu", $rest, $h ) ) {
			return null;
		}
		$host = $h[0];
		if ( 'www.' === strtolower( $prefix ) ) {
			$name = (string) preg_replace( '/:\d+$/', '', $host );
			$tld  = mb_strtolower( (string) substr( $name, (int) strrpos( $name, '.' ) + 1 ) );
			if ( ! str_contains( $name, '.' ) || ! preg_match( '/^(?:' . self::TLDS . ')$/u', $tld ) ) {
				return null;
			}
		}
		$after = substr( $rest, strlen( $host ) );
		$path  = '' !== $after && str_contains( '/?#', $after[0] ) ? self::trim_path( self::scan_path( $after ) ) : '';
		return $prefix . $host . $path;
	}

	/**
	 * The path, up to a space, one of < > " ' | \ (a backslash starts a Markdown
	 * escape, and the writer escapes what follows the address), or a closing
	 * bracket with no matching opener ("(see https://x.com/p)" ends before ")").
	 */
	private static function scan_path( string $s ): string {
		$open  = array( '(' => ')', '[' => ']', '{' => '}' );
		$depth = array( ')' => 0, ']' => 0, '}' => 0 );
		preg_match( '/^[^' . Text::WS . '<>"\'|｜\\\\]*/u', $s, $m ); // up to the first stop character
		$chars = mb_str_split( $m[0] ?? '' );
		$out   = '';
		foreach ( $chars as $c ) {
			if ( isset( $open[ $c ] ) ) {
				++$depth[ $open[ $c ] ];
			} elseif ( isset( $depth[ $c ] ) ) {
				if ( 0 === $depth[ $c ] ) {
					break;
				}
				--$depth[ $c ];
			}
			$out .= $c;
		}
		return $out;
	}

	/** Trailing punctuation and unbalanced closing brackets aren't part of the address. */
	public static function trim_path( string $u ): string {
		$pairs = array( ')' => '(', ']' => '[', '}' => '{' );
		while ( '' !== $u ) {
			$last = substr( $u, -1 );
			if ( str_contains( '.,;:!?\'"*_~', $last ) ) {
				$u = substr( $u, 0, -1 );
				continue;
			}
			if ( isset( $pairs[ $last ] ) && substr_count( $u, $pairs[ $last ] ) < substr_count( $u, $last ) ) {
				$u = substr( $u, 0, -1 );
				continue;
			}
			break;
		}
		return $u;
	}

	/**
	 * Every address in $text.
	 *
	 * @return array<int, array{0:int, 1:string}> [byte offset, address]
	 */
	public static function find_all( string $text ): array {
		$found = array();
		if ( ! preg_match_all( '/(?<![A-Za-z0-9_])(?:https?:\/\/|www\.)/i', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return $found;
		}
		$next = 0;
		foreach ( $m[0] as [ , $at ] ) {
			if ( $at < $next ) {
				continue;
			}
			$url = self::match_at( substr( $text, $at ) );
			if ( null !== $url ) {
				$found[] = array( $at, $url );
				$next    = $at + strlen( $url );
			}
		}
		return $found;
	}
}
