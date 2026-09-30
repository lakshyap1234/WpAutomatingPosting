<?php
/**
 * Structure map + original lines -> Markdown (the editable format).
 *
 * Deterministic: the AI only picked line roles; this writes the Markdown from
 * the original text, escaped so nothing in the text is read as Markdown syntax.
 *
 * Port of mapToMarkdown and its escaping in the prototype's src/markdown.js.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

use CPub\Publisher\Vendor\League\CommonMark\Util\RegexHelper;
use CPub\Publisher\Vendor\League\CommonMark\Util\UrlEncoder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MarkdownWriter {

	/** Escape inline syntax in text that contains no URLs. */
	public static function escape_inline( string $s ): string {
		$s = str_replace( '\\', '\\\\', $s );
		$s = (string) preg_replace( '/([*_`\[\]<])/', '\\\\$1', $s );
		return (string) preg_replace( '/&(?=#?[A-Za-z0-9_]+;)/', '\\\\&', $s ); // "&copy;" must stay literal
	}

	/** Escape a line's inline syntax, leaving URLs verbatim so they still link. */
	public static function escape_text( string $text ): string {
		$out  = '';
		$last = 0;
		foreach ( BareUrl::find_all( $text ) as [ $at, $url ] ) {
			$out .= self::escape_inline( substr( $text, $last, $at - $last ) ) . $url;
			$last = $at + strlen( $url );
		}
		return $out . self::escape_inline( substr( $text, $last ) );
	}

	/** Escape what only means something at the start of a line. */
	public static function escape_line_start( string $s ): string {
		$ws = Text::WS;
		$s  = (string) preg_replace_callback( "/^(#{1,6})(?=[{$ws}]|$)/u", fn( $m ) => str_replace( '#', '\\#', $m[0] ), $s ); // heading
		$s  = (string) preg_replace( '/^>/', '\\\\>', $s );                                                                          // quote
		$s  = (string) preg_replace( "/^([-+])(?=[{$ws}]|$)/u", '\\\\$1', $s );                                                     // bullet (also a lone "+", which is an empty list item)
		$s  = (string) preg_replace( "/^([0-9]{1,9})([.)])(?=[{$ws}]|$)/u", '$1\\\\$2', $s );                                       // numbered list
		$s  = (string) preg_replace( "/^([=-])(?=[=-]*[{$ws}]*$)/u", '\\\\$1', $s );                                                // setext / rule
		return (string) preg_replace( '/^(~~~)/', '\\\\$1', $s );                                                                   // code fence
	}

	/** Plain text -> one safe Markdown line. */
	public static function line( string $text ): string {
		return self::escape_line_start( self::escape_text( $text ) );
	}

	/**
	 * @return array{markdown:string, removed:array, body_text:string, images:array}
	 *   body_text is the plain text the Markdown must render to (fidelity check).
	 */
	public static function from_map( array $map, array $lines ): array {
		$t      = fn( int $id ) => $lines[ $id - 1 ]['text'];
		$src    = fn( array $ids ) => array_map( fn( $id ) => $lines[ $id - 1 ], $ids );
		$parts  = array( '# ' . self::heading_text( $t( $map['title'] ) ) );
		$notes  = array();
		$body   = array();
		$images = array();
		$prev   = null; // ordered-ness of the previous block if it was a list
		$flip   = false;

		foreach ( $map['blocks'] as $b ) {
			switch ( $b['type'] ) {
				case 'heading':
					$parts[] = str_repeat( '#', $b['level'] ) . ' ' . self::heading_text( $t( $b['lines'][0] ) );
					$body[]  = $t( $b['lines'][0] );
					$prev    = null;
					break;
				case 'paragraph':
					$texts = array_map( $t, $b['lines'] );
					if ( ! empty( $b['breaks'] ) ) {
						$n       = count( $texts );
						$parts[] = implode( "\n", array_map( fn( $x, $i ) => self::line( $x ) . ( $i < $n - 1 ? '\\' : '' ), $texts, array_keys( $texts ) ) );
					} else {
						$parts[] = self::line( implode( ' ', $texts ) );
					}
					$body[] = implode( ' ', $texts );
					$prev   = null;
					break;
				case 'table':
					$rows    = Blocks::parse_table( $src( $b['lines'] ) )['rows'];
					$parts[] = self::table( $rows, (bool) $b['header'] );
					$body[]  = Blocks::table_text( $rows );
					$prev    = null;
					break;
				case 'image':
					$img      = Blocks::parse_image( $src( $b['lines'] ) );
					$url      = '' !== $img['url'] ? $img['url'] : $img['filename'];
					[ $md, $published ] = self::image( $url, $img['alt'], $img['caption'] );
					$parts[]            = $md;
					// The address as it will be published (percent-encoded, like browsers send it).
					$body[]   = Blocks::image_text( array( 'url' => $published, 'alt' => $img['alt'], 'caption' => $img['caption'] ) );
					$images[] = array( 'src' => $published, 'filename' => $img['filename'] );
					$prev     = null;
					break;
				default: // list
					// Two lists in a row merge in Markdown unless the marker changes.
					$flip  = null !== $prev && $prev === $b['ordered'] ? ! $flip : false;
					$items = array();
					// Source markers ("- ", "1. ", "a) ") are replaced by the list's own, but only
					// when every item has one: otherwise "I. M. Pei…" could lose its initial.
					$strip = true;
					foreach ( $b['items'] as $ids ) {
						$strip = $strip && Text::strip_list_marker( $t( $ids[0] ) ) !== $t( $ids[0] );
					}
					foreach ( $b['items'] as $i => $ids ) {
						$first = $strip ? Text::strip_list_marker( $t( $ids[0] ) ) : $t( $ids[0] );
						if ( $strip && preg_match( '/^[a-zA-Z][.)]/', $t( $ids[0] ) ) ) {
							$notes[] = "Line {$ids[0]}: “" . substr( $t( $ids[0] ), 0, 2 ) . '” was treated as a list letter and removed. If it is part of the text (an initial, say), make that line a paragraph.';
						}
						$text    = implode( ' ', array_merge( array( $first ), array_map( $t, array_slice( $ids, 1 ) ) ) );
						$body[]  = $text;
						$marker  = $b['ordered'] ? ( $i + 1 ) . ( $flip ? ')' : '.' ) : ( $flip ? '*' : '-' );
						$items[] = $marker . ' ' . self::line( $text );
					}
					$parts[] = implode( "\n", $items );
					$prev    = $b['ordered'];
			}
		}

		return array(
			'markdown'  => implode( "\n\n", $parts ) . "\n",
			'removed'   => array_map( fn( $e ) => array( 'line' => $e['line'], 'kind' => $e['kind'], 'text' => $t( $e['line'] ) ), $map['excluded'] ),
			'body_text' => implode( ' ', $body ),
			'images'    => $images,
			'notes'     => $notes,
		);
	}

	/**
	 * Title or heading text. Inside a heading only a closing run of # (one that
	 * follows a space) is syntax; # anywhere else is text and, in an address,
	 * must stay as it is.
	 */
	private static function heading_text( string $text ): string {
		$ws = Text::WS;
		return Text::must( preg_replace( "/(^|[{$ws}])#(?=#*[{$ws}]*$)/u", '$1\\\\#', self::escape_text( $text ) ) );
	}

	/** A cell: normal escaping plus | so it can't end the cell. */
	private static function cell( string $text ): string {
		return str_replace( '|', '\\|', self::escape_text( $text ) );
	}

	/** GFM table. A table without a header row gets an empty one, read back as "no header". */
	private static function table( array $rows, bool $header ): string {
		$width = count( $rows[0] );
		$row   = fn( array $cells ) => '| ' . implode( ' | ', array_map( array( self::class, 'cell' ), $cells ) ) . ' |';
		$out   = array( $header ? $row( $rows[0] ) : '|' . str_repeat( ' |', $width ), '|' . str_repeat( ' --- |', $width ) );
		foreach ( $header ? array_slice( $rows, 1 ) : $rows as $r ) {
			$out[] = $row( $r );
		}
		return implode( "\n", $out );
	}

	/**
	 * ![alt](src "caption"). The caption travels in the Markdown title slot.
	 *
	 * @return array{0:string, 1:string} [Markdown, the address as the parser will publish it]
	 */
	private static function image( string $src, string $alt, string $caption ): array {
		// The parser unescapes backslashes and then decodes entities in the address and
		// title (in that order, so "\&amp;" still becomes "&"): write "&" as "&amp;" there.
		$entity = '/&(?=#?[A-Za-z0-9_]+;)/';
		$inner  = Text::must( preg_replace( $entity, '&amp;', str_replace( '\\', '\\\\', $src ) ) );
		$dest  = preg_match( '/[' . Text::WS . '()<>]/u', $src ) ? '<' . str_replace( array( '<', '>' ), array( '%3C', '%3E' ), $inner ) . '>' : $inner;
		$title = '' !== $caption ? ' "' . Text::must( preg_replace( $entity, '&amp;', str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $caption ) ) ) . '"' : '';
		$published = UrlEncoder::unescapeAndEncode( RegexHelper::unescape( '<' === $dest[0] ? substr( $dest, 1, -1 ) : $dest ) );
		return array( '![' . self::escape_inline( $alt ) . '](' . $dest . $title . ')', $published );
	}
}
