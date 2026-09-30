<?php
/**
 * Deterministic parsing of tables and image references. The AI only says
 * WHICH lines form a table or image; this decides the cells and fields from
 * the original text, so the AI can't change a word inside them.
 *
 * Port of the prototype's src/blocks.js.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Blocks {

	public const MAX_COLUMNS = 20;

	/** "| a | b \| c |" -> ["a", "b | c"] */
	private static function split_pipe_row( string $text ): array {
		$s = Text::trim( $text );
		if ( str_starts_with( $s, '|' ) ) {
			$s = substr( $s, 1 );
		}
		if ( str_ends_with( $s, '|' ) && ! str_ends_with( $s, '\\|' ) ) {
			$s = substr( $s, 0, -1 );
		}
		$cells = array();
		$cur   = '';
		$len   = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( '\\' === $s[ $i ] && '|' === ( $s[ $i + 1 ] ?? '' ) ) {
				$cur .= '|';
				++$i;
			} elseif ( '|' === $s[ $i ] ) {
				$cells[] = $cur;
				$cur     = '';
			} else {
				$cur .= $s[ $i ];
			}
		}
		$cells[] = $cur;
		return array_map( array( Text::class, 'trim' ), $cells );
	}

	private static function is_separator_row( array $cells ): bool {
		if ( ! $cells ) {
			return false;
		}
		foreach ( $cells as $c ) {
			if ( ! preg_match( '/^:?-{1,}:?$/', $c ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array $lines the table's source lines, in order
	 * @return array{ok:true, format:string, rows:array, header:?bool, ragged:bool}|array{ok:false, error:string}
	 */
	public static function parse_table( array $lines ): array {
		$first = $lines[0]['id'];
		$last  = $lines[ count( $lines ) - 1 ]['id'];
		$range = count( $lines ) > 1 ? "Lines {$first}–{$last}" : "Line {$first}";
		$header = null;

		if ( array_reduce( $lines, fn( $ok, $l ) => $ok && str_contains( $l['raw'], "\t" ), true ) ) {
			$format = 'tab';
			$rows   = array_map( fn( $l ) => array_map( array( Text::class, 'trim' ), explode( "\t", $l['raw'] ) ), $lines );
		} elseif ( array_reduce( $lines, fn( $ok, $l ) => $ok && str_starts_with( $l['text'], '|' ), true ) ) {
			$format = 'pipe';
			$split  = array_map( fn( $l ) => self::split_pipe_row( $l['text'] ), $lines );
			$sep    = -1;
			foreach ( $split as $i => $r ) {
				if ( self::is_separator_row( $r ) ) {
					$sep = $i;
					break;
				}
			}
			if ( 1 === $sep ) {
				$header = true;
			}
			$rows = array_values( array_filter( $split, fn( $r ) => ! self::is_separator_row( $r ) ) );
		} else {
			return array(
				'ok'    => false,
				'error' => "{$range} can't be split into columns. Only tab-separated tables or tables with | pipes | on every line are supported; mark lines that are not like that as paragraphs, not a table.",
			);
		}

		if ( ! $rows ) {
			return array( 'ok' => false, 'error' => "{$range}: the table has no rows." );
		}
		$width = max( array_map( 'count', $rows ) );
		if ( $width < 2 ) {
			return array( 'ok' => false, 'error' => "{$range} have only one column, so they are not a table." );
		}
		if ( $width > self::MAX_COLUMNS ) {
			return array( 'ok' => false, 'error' => "{$range}: more than " . self::MAX_COLUMNS . " columns isn't supported." );
		}
		$ragged = false;
		foreach ( $rows as $i => $r ) {
			if ( count( $r ) !== $width ) {
				$ragged     = true;
				$rows[ $i ] = array_merge( $r, array_fill( 0, $width - count( $r ), '' ) );
			}
		}
		return array( 'ok' => true, 'format' => $format, 'rows' => $rows, 'header' => $header, 'ragged' => $ragged );
	}

	/** key => [regex, label] */
	private static function fields(): array {
		return array(
			// "[IMAGE]" or "[IMAGE: grinder-hero.jpg]" (also IMG / PHOTO / PICTURE / FIGURE)
			'marker'  => array( '/^\[\s*(?:image|img|photo|picture|figure)\s*(?::\s*(.*?))?\s*\]$/iu', '[IMAGE] or [IMAGE: file name]' ),
			'url'     => array( '/^image\s*(?:url|link|src)\s*:\s*(\S+)$/iu', 'Image URL:' ),
			'alt'     => array( '/^alt(?:\s*text)?\s*:\s*(.*)$/iu', 'Alt text:' ),
			'caption' => array( '/^caption\s*:\s*(.*)$/iu', 'Caption:' ),
			// Attribution. Kept word for word (label included): licences like CC BY-SA require it.
			'credit'  => array( '/^(?:(?:image|photo)\s+)?(?:credit|credits|source|attribution|photo\s+by)\s*:\s*(.*)$/iu', 'Credit: / Source:' ),
		);
	}

	/**
	 * Lines that can only belong to an image (marker, URL, alt). Caption and
	 * credit are left out on purpose: "Source: …" under a table is ordinary text.
	 */
	public static function looks_like_image_line( string $text ): bool {
		$t = Text::trim( $text );
		foreach ( array_slice( self::fields(), 0, 3 ) as [ $re ] ) {
			if ( preg_match( $re, $t ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{ok:true, filename:string, url:string, alt:string, caption:string, credit:string}|array{ok:false, error:string}
	 */
	public static function parse_image( array $lines ): array {
		$out    = array( 'filename' => '', 'url' => '', 'alt' => '', 'caption' => '', 'credit' => '' );
		$seen   = array();
		$fields = self::fields();
		foreach ( $lines as $l ) {
			$key = null;
			foreach ( $fields as $k => [ $re ] ) {
				if ( preg_match( $re, $l['text'], $m ) ) {
					$key = $k;
					break;
				}
			}
			if ( null === $key ) {
				$labels = implode( ', ', array_column( $fields, 1 ) );
				return array( 'ok' => false, 'error' => "Line {$l['id']} isn't part of an image. An image is only these lines: {$labels}. Put line {$l['id']} in a paragraph or heading instead." );
			}
			if ( isset( $seen[ $key ] ) ) {
				return array( 'ok' => false, 'error' => "Line {$l['id']}: the image has two \"{$fields[$key][1]}\" lines; they belong to separate images." );
			}
			$seen[ $key ] = true;
			$value        = Text::trim( $m[1] ?? '' );
			if ( 'marker' === $key ) {
				$out['filename'] = $value;
			} elseif ( 'credit' === $key ) {
				$out['credit'] = Text::trim( $l['text'] );
			} else {
				$out[ $key ] = $value;
			}
		}
		if ( ! isset( $seen['marker'] ) && '' === $out['url'] ) {
			$first = $lines[0]['id'];
			$last  = $lines[ count( $lines ) - 1 ]['id'];
			return array( 'ok' => false, 'error' => "Lines {$first}–{$last} have no [IMAGE] or Image URL: line, so they are not an image." );
		}
		return array( 'ok' => true ) + array_merge( $out, array( 'caption' => self::full_caption( $out ) ) );
	}

	/** The text under the image: the caption, then the credit line as written. */
	public static function full_caption( array $img ): string {
		return implode( ' ', array_filter( array( $img['caption'] ?? '', $img['credit'] ?? '' ), 'strlen' ) );
	}

	public static function table_text( array $rows ): string {
		return implode( ' ', array_filter( array_merge( ...$rows ), 'strlen' ) );
	}

	public static function image_text( array $img ): string {
		return implode( ' ', array_filter( array( $img['url'] ?? '', $img['alt'] ?? '', $img['caption'] ?? '' ), 'strlen' ) );
	}
}
