<?php
/**
 * Deterministic first step. No AI here: decode the file, normalise it, and
 * split it into numbered lines. The text in `lines` is the only text that ever
 * reaches WordPress; the AI only sees and returns line numbers.
 *
 * Port of the prototype's src/ingest.js.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ingest {

	/**
	 * Decode a text file's bytes: UTF-8 (with or without BOM), UTF-16 (Windows
	 * Notepad "Unicode"), and Windows-1252 as a fallback for legacy files.
	 *
	 * @return array{text:string, encoding:string}
	 */
	public static function decode( string $bytes ): array {
		if ( str_starts_with( $bytes, "\xFF\xFE" ) ) {
			return array( 'text' => (string) mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' ), 'encoding' => 'utf-16le' );
		}
		if ( str_starts_with( $bytes, "\xFE\xFF" ) ) {
			return array( 'text' => (string) mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' ), 'encoding' => 'utf-16be' );
		}
		if ( mb_check_encoding( $bytes, 'UTF-8' ) ) {
			return array( 'text' => str_starts_with( $bytes, "\xEF\xBB\xBF" ) ? substr( $bytes, 3 ) : $bytes, 'encoding' => 'utf-8' );
		}
		return array( 'text' => (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' ), 'encoding' => 'windows-1252 (fallback)' );
	}

	/**
	 * Normalise without changing meaning: line endings, Unicode NFC, stray BOMs,
	 * non-breaking spaces, trailing whitespace. Nothing else is touched.
	 */
	public static function normalize( string $text ): string {
		$text = Text::must( preg_replace( '/^\x{FEFF}/u', '', $text ) );
		$text = Text::must( preg_replace( '/\r\n?/', "\n", $text ) );
		if ( class_exists( \Normalizer::class ) ) {
			$text = (string) \Normalizer::normalize( $text, \Normalizer::FORM_C );
		}
		$text  = str_replace( "\u{00A0}", ' ', $text );
		$lines = array_map( fn( $l ) => rtrim( $l, " \t" ), explode( "\n", $text ) );
		return trim( implode( "\n", $lines ), "\n" );
	}

	/**
	 * Numbered content lines (1-based, blanks don't get numbers) and the layout
	 * including blank separators (runs of blanks collapsed).
	 *
	 * @return array{lines: array<int, array{id:int, raw:string, text:string}>, layout: array}
	 */
	public static function to_lines( string $text ): array {
		$layout = array();
		$lines  = array();
		foreach ( explode( "\n", $text ) as $raw ) {
			if ( '' === Text::trim( $raw ) ) {
				$last = end( $layout );
				if ( $last && ! empty( $last['blank'] ) ) {
					continue;
				}
				$layout[] = array( 'blank' => true );
			} else {
				$line     = array( 'id' => count( $lines ) + 1, 'raw' => $raw, 'text' => Text::trim( $raw ) );
				$lines[]  = $line;
				$layout[] = $line;
			}
		}
		return array( 'lines' => $lines, 'layout' => $layout );
	}

	/** What the model sees. Tabs are shown as ⇥ so it can spot tab-separated tables. */
	public static function render_for_prompt( array $layout ): string {
		return implode(
			"\n",
			array_map(
				fn( $l ) => ! empty( $l['blank'] ) ? '        (blank)' : 'L' . str_pad( (string) $l['id'], 4, ' ', STR_PAD_LEFT ) . ' | ' . str_replace( "\t", ' ⇥ ', $l['raw'] ),
				$layout
			)
		);
	}

	/**
	 * @return array{encoding:string, source_hash:string, lines:array, layout:array}
	 * @throws \InvalidArgumentException when the file is empty
	 */
	public static function ingest( string $bytes ): array {
		$decoded    = self::decode( $bytes );
		$normalized = self::normalize( $decoded['text'] );
		if ( '' === $normalized ) {
			throw new \InvalidArgumentException( 'Input file is empty after normalization.' );
		}
		$split = self::to_lines( $normalized );
		return array(
			'encoding'    => $decoded['encoding'],
			'source_hash' => hash( 'sha256', $normalized ),
			'lines'       => $split['lines'],
			'layout'      => $split['layout'],
		);
	}
}
