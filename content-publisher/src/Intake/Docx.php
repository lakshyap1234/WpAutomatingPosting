<?php
/**
 * Word (.docx) → plain text lines in the same shape as a supplied .txt post,
 * so it goes through exactly the same AI structuring and fidelity check.
 *
 * - Paragraphs are separated by a blank line; a line break inside a paragraph
 *   stays a line break (addresses).
 * - List items get a "- " or "1. " marker (the writer replaces it with the
 *   list's own) and no blank lines between them.
 * - Table rows become tab-separated lines (the tab table format).
 * - Embedded images are taken out as files and replaced by an
 *   "[IMAGE: image1.png]" line, with "Alt text: …" from Word's alt text and
 *   "Caption: …" from a Caption-styled paragraph right after the image.
 * - Tracked changes are read as accepted: insertions kept, deletions dropped.
 * - Field codes are dropped, their displayed result kept.
 * - Formatting (bold, italics) is not carried over. Links are listed in
 *   `links` so the reviewer can put them back.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Intake;

use CPub\Publisher\Jobs\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Docx {

	public const MAX_FILE_BYTES = 26214400; // 25 MB: text plus a few photos
	private const MAX_XML_BYTES = 20971520; // 20 MB of document XML

	private const W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const A   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
	private const WP  = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
	private const V   = 'urn:schemas-microsoft-com:vml';
	private const MC  = 'http://schemas.openxmlformats.org/markup-compatibility/2006';
	private const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

	private ZipReader $zip;
	/** @var array<string, array{target:string, external:bool}> */
	private array $rels = array();
	/** @var array<string, array<int, array{fmt:string, start:int}>> numId => ilvl => format */
	private array $numbering = array();
	/** @var array<string, array<int,int>> numId => ilvl => counter */
	private array $counters = array();
	/** @var array<string,string> styleId => lowercase style name */
	private array $styles = array();
	/** @var array<string, array{0:string,1:int}> styleId => [numId, ilvl] for list styles */
	private array $style_numbering = array();
	/** Whether the last block ends with a list item. */
	private bool $in_list = false;

	/** @var string[][] blocks of lines */
	private array $blocks = array();
	/** @var array<string, array{name:string, bytes:string, mime:string}> media path => image */
	private array $images = array();
	/** @var array<int, array{text:string, url:string}> */
	private array $links = array();
	/** @var array<string,string> */
	private array $notes = array();
	/** Index of the last block that is an image (for a following caption). */
	private ?int $last_image_block = null;

	/**
	 * @return array{text:string, images: array<int, array{name:string, bytes:string, mime:string}>, links: array<int, array{text:string, url:string}>, notes: string[]}
	 * @throws IntakeException
	 */
	public static function read( string $bytes ): array {
		if ( strlen( $bytes ) > self::MAX_FILE_BYTES ) {
			throw new IntakeException( 'The Word file is larger than 25 MB. Compress its images (File > Compress Pictures in Word) or upload them separately.' );
		}
		if ( ! str_starts_with( $bytes, "PK\x03\x04" ) ) {
			throw new IntakeException( 'This isn\'t a valid Word (.docx) file. If it\'s an old .doc file, save it as .docx and upload it again.' );
		}
		$self      = new self();
		$self->zip = new ZipReader( $bytes );
		return $self->run();
	}

	private function run(): array {
		$main = $this->main_part();
		$doc  = $this->xml( $main );
		if ( ! $doc ) {
			throw new IntakeException( 'This Word file has no document text.' );
		}
		$dir        = str_contains( $main, '/' ) ? substr( $main, 0, (int) strrpos( $main, '/' ) + 1 ) : '';
		$this->rels = $this->relationships( $dir . '_rels/' . basename( $main ) . '.rels', $dir );
		$this->load_numbering( $dir );
		$this->load_styles( $dir );

		$body = $this->first( $doc->documentElement, self::W, 'body' );
		if ( $body ) {
			$this->container( $body );
		}
		$lines = array();
		foreach ( $this->blocks as $b ) {
			$b = array_values( array_filter( $b, fn( $l ) => '' !== trim( $l ) ) );
			if ( $b ) {
				$lines[] = implode( "\n", $b );
			}
		}
		$text = implode( "\n\n", $lines );
		if ( '' === trim( $text ) ) {
			throw new IntakeException( 'The Word file has no text.' );
		}
		return array(
			'text'   => $text . "\n",
			'images' => array_values( $this->images ),
			'links'  => $this->links,
			'notes'  => array_values( $this->notes ),
		);
	}

	// ---------------------------------------------------------------- package

	/** The main document part, from [Content_Types].xml's officeDocument relationship. */
	private function main_part(): string {
		$rels = $this->relationships( '_rels/.rels', '' );
		foreach ( $rels as $r ) {
			if ( str_ends_with( $r['type'] ?? '', '/officeDocument' ) && ! $r['external'] ) {
				return ltrim( $r['target'], '/' );
			}
		}
		return 'word/document.xml';
	}

	/** @return array<string, array{target:string, external:bool, type?:string}> */
	private function relationships( string $part, string $base ): array {
		$doc = $this->xml( $part );
		$out = array();
		if ( ! $doc ) {
			return $out;
		}
		foreach ( $doc->getElementsByTagNameNS( self::REL, 'Relationship' ) as $r ) {
			$external             = 'External' === $r->getAttribute( 'TargetMode' );
			$target               = $r->getAttribute( 'Target' );
			$out[ $r->getAttribute( 'Id' ) ] = array(
				'target'   => $external ? $target : self::resolve( $base, $target ),
				'external' => $external,
				'type'     => $r->getAttribute( 'Type' ),
			);
		}
		return $out;
	}

	/** "media/image1.png" relative to "word/" → "word/media/image1.png"; handles ../ and absolute. */
	private static function resolve( string $base, string $target ): string {
		$path  = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : $base . $target;
		$parts = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $parts );
			} elseif ( '' !== $seg && '.' !== $seg ) {
				$parts[] = $seg;
			}
		}
		return implode( '/', $parts );
	}

	private function xml( string $part ): ?\DOMDocument {
		$raw = $this->zip->get( $part, self::MAX_XML_BYTES );
		if ( null === $raw ) {
			return null;
		}
		if ( preg_match( '/<!DOCTYPE/i', substr( $raw, 0, 4096 ) ) ) {
			throw new IntakeException( 'The Word file contains an unexpected document type declaration and was refused.' );
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadXML( $raw, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			throw new IntakeException( "Part of the Word file ({$part}) is damaged." );
		}
		if ( $doc->doctype ) { // Word never writes one; entities could smuggle text in
			throw new IntakeException( 'The Word file contains an unexpected document type declaration and was refused.' );
		}
		return $doc;
	}

	private function load_numbering( string $dir ): void {
		$part = null;
		foreach ( $this->rels as $r ) {
			if ( str_ends_with( $r['type'] ?? '', '/numbering' ) && ! $r['external'] ) {
				$part = $r['target'];
			}
		}
		$doc = $this->xml( $part ?? $dir . 'numbering.xml' );
		if ( ! $doc ) {
			return;
		}
		$abstract = array();
		foreach ( $doc->getElementsByTagNameNS( self::W, 'abstractNum' ) as $an ) {
			$levels = array();
			foreach ( $this->children( $an, self::W, 'lvl' ) as $lvl ) {
				$fmt   = $this->first( $lvl, self::W, 'numFmt' );
				$start = $this->first( $lvl, self::W, 'start' );
				$levels[ (int) $lvl->getAttributeNS( self::W, 'ilvl' ) ] = array(
					'fmt'   => $fmt ? $fmt->getAttributeNS( self::W, 'val' ) : 'decimal',
					'start' => $start ? (int) $start->getAttributeNS( self::W, 'val' ) : 1,
				);
			}
			$abstract[ $an->getAttributeNS( self::W, 'abstractNumId' ) ] = $levels;
		}
		foreach ( $doc->getElementsByTagNameNS( self::W, 'num' ) as $num ) {
			$ref    = $this->first( $num, self::W, 'abstractNumId' );
			$levels = $ref ? ( $abstract[ $ref->getAttributeNS( self::W, 'val' ) ] ?? array() ) : array();
			foreach ( $this->children( $num, self::W, 'lvlOverride' ) as $ov ) {
				$so = $this->first( $ov, self::W, 'startOverride' );
				$il = (int) $ov->getAttributeNS( self::W, 'ilvl' );
				if ( $so && isset( $levels[ $il ] ) ) {
					$levels[ $il ]['start'] = (int) $so->getAttributeNS( self::W, 'val' );
				}
			}
			$this->numbering[ $num->getAttributeNS( self::W, 'numId' ) ] = $levels;
		}
	}

	private function load_styles( string $dir ): void {
		$part = null;
		foreach ( $this->rels as $r ) {
			if ( str_ends_with( $r['type'] ?? '', '/styles' ) && ! $r['external'] ) {
				$part = $r['target'];
			}
		}
		$doc = $this->xml( $part ?? $dir . 'styles.xml' );
		if ( ! $doc ) {
			return;
		}
		$based = array();
		$own   = array();
		foreach ( $doc->getElementsByTagNameNS( self::W, 'style' ) as $st ) {
			$id                  = $st->getAttributeNS( self::W, 'styleId' );
			$name                = $this->first( $st, self::W, 'name' );
			$this->styles[ $id ] = strtolower( $name ? $name->getAttributeNS( self::W, 'val' ) : '' );
			$b                   = $this->first( $st, self::W, 'basedOn' );
			$based[ $id ]        = $b ? $b->getAttributeNS( self::W, 'val' ) : '';
			$np                  = $this->first( $this->first( $st, self::W, 'pPr' ), self::W, 'numPr' );
			if ( $np ) {
				$nid         = $this->first( $np, self::W, 'numId' );
				$lvl         = $this->first( $np, self::W, 'ilvl' );
				$own[ $id ] = array( $nid ? $nid->getAttributeNS( self::W, 'val' ) : '0', $lvl ? (int) $lvl->getAttributeNS( self::W, 'val' ) : 0 );
			}
		}
		// List styles ("List Bullet", "List Number") number their paragraphs; styles inherit it.
		foreach ( array_keys( $this->styles ) as $id ) {
			for ( $s = $id, $hops = 0; '' !== $s && $hops < 20; $s = $based[ $s ] ?? '', $hops++ ) {
				if ( isset( $own[ $s ] ) ) {
					$this->style_numbering[ $id ] = $own[ $s ];
					break;
				}
			}
		}
	}

	// ---------------------------------------------------------------- body

	/** Body, table cell, content control…: a sequence of paragraphs and tables. */
	private function container( \DOMElement $el ): void {
		foreach ( $el->childNodes as $n ) {
			if ( ! $n instanceof \DOMElement ) {
				continue;
			}
			if ( self::W === $n->namespaceURI ) {
				switch ( $n->localName ) {
					case 'p':
						$this->paragraph( $n );
						break;
					case 'tbl':
						$this->table( $n );
						break;
					case 'sdt':
						$content = $this->first( $n, self::W, 'sdtContent' );
						if ( $content ) {
							$this->container( $content );
						}
						break;
					case 'customXml':
					case 'ins':
					case 'moveTo':
						$this->container( $n );
						break;
				}
			} elseif ( self::MC === $n->namespaceURI && 'AlternateContent' === $n->localName ) {
				$pick = $this->first( $n, self::MC, 'Choice' ) ?? $this->first( $n, self::MC, 'Fallback' );
				if ( $pick ) {
					$this->container( $pick );
				}
			}
		}
	}

	private function paragraph( \DOMElement $p ): void {
		$state = array( 'text' => '', 'images' => array(), 'boxes' => array(), 'fields' => array() );
		$this->inline( $p, $state );
		$text  = self::clean( $state['text'] );
		$ppr   = $this->first( $p, self::W, 'pPr' );
		$style = '';
		$num   = null;
		if ( $ppr ) {
			$ps       = $this->first( $ppr, self::W, 'pStyle' );
			$style_id = $ps ? $ps->getAttributeNS( self::W, 'val' ) : '';
			$style    = $this->styles[ $style_id ] ?? strtolower( $style_id );
			$num      = $this->style_numbering[ $style_id ] ?? null;
			$np       = $this->first( $ppr, self::W, 'numPr' );
			if ( $np ) {
				$id  = $this->first( $np, self::W, 'numId' );
				$lvl = $this->first( $np, self::W, 'ilvl' );
				$num = array( $id ? $id->getAttributeNS( self::W, 'val' ) : ( $num[0] ?? '0' ), $lvl ? (int) $lvl->getAttributeNS( self::W, 'val' ) : ( $num[1] ?? 0 ) );
			}
			if ( $num && '0' === $num[0] ) {
				$num = null; // numId 0 = numbering switched off
			}
		}

		if ( '' !== trim( $text ) ) {
			if ( str_ends_with( $style, 'caption' ) && null !== $this->last_image_block && ! $state['images'] && count( $this->blocks ) - 1 === $this->last_image_block && ! $this->has_caption( $this->last_image_block ) ) {
				// Word's caption under the image: part of the image.
				$this->blocks[ $this->last_image_block ][] = 'Caption: ' . str_replace( "\n", ' ', $text );
			} elseif ( $num ) {
				$marker = $this->marker( $num[0], $num[1] );
				$line   = $marker . str_replace( "\n", ' ', $text );
				if ( $this->in_list && $this->blocks ) {
					$this->blocks[ count( $this->blocks ) - 1 ][] = $line;
				} else {
					$this->blocks[] = array( $line );
				}
				$this->in_list = true;
			} else {
				$this->blocks[] = explode( "\n", $text );
				$this->in_list  = false;
			}
		}
		foreach ( $state['images'] as $img ) {
			$this->image_block( $img );
		}
		foreach ( $state['boxes'] as $box ) {
			$this->notes['textbox'] = 'The Word file has text boxes; their text was placed after the paragraph they belong to. Check its position.';
			$this->container( $box );
		}
	}

	private function has_caption( int $block ): bool {
		foreach ( $this->blocks[ $block ] as $l ) {
			if ( str_starts_with( $l, 'Caption: ' ) ) {
				return true;
			}
		}
		return false;
	}

	/** "- " for bullets, "3. " for numbered items, counting per list and level. */
	private function marker( string $num_id, int $lvl ): string {
		$def = $this->numbering[ $num_id ][ $lvl ] ?? array( 'fmt' => 'bullet', 'start' => 1 );
		if ( $lvl > 0 ) {
			$this->notes['nested'] = 'The Word file has lists inside lists; they were flattened into one level. Check the lists.';
		}
		// A new item at this level restarts every deeper level.
		foreach ( array_keys( $this->counters[ $num_id ] ?? array() ) as $l ) {
			if ( $l > $lvl ) {
				unset( $this->counters[ $num_id ][ $l ] );
			}
		}
		if ( 'bullet' === $def['fmt'] ) {
			return '- ';
		}
		if ( 'none' === $def['fmt'] ) {
			return '';
		}
		$n                                   = $this->counters[ $num_id ][ $lvl ] ?? ( $def['start'] - 1 );
		$this->counters[ $num_id ][ $lvl ] = ++$n;
		return $n . '. ';
	}

	private function table( \DOMElement $tbl ): void {
		$rows   = array();
		$images = array();
		foreach ( $this->children( $tbl, self::W, 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $this->row_cells( $tr ) as $tc ) {
				$state = array( 'text' => '', 'images' => array(), 'boxes' => array(), 'fields' => array() );
				$parts = array();
				foreach ( $tc->getElementsByTagNameNS( self::W, 'p' ) as $p ) {
					$state['text'] = '';
					$this->inline( $p, $state );
					$parts[] = self::clean( $state['text'] );
				}
				$images  = array_merge( $images, $state['images'] );
				$text    = trim( (string) preg_replace( '/[\t\n ]+/u', ' ', implode( ' ', $parts ) ) );
				$vmerge  = $this->first( $this->first( $tc, self::W, 'tcPr' ) ?? $tc, self::W, 'vMerge' );
				$cells[] = $vmerge && 'restart' !== $vmerge->getAttributeNS( self::W, 'val' ) ? '' : $text;
				$span    = $this->first( $this->first( $tc, self::W, 'tcPr' ) ?? $tc, self::W, 'gridSpan' );
				for ( $i = 1; $span && $i < min( 20, (int) $span->getAttributeNS( self::W, 'val' ) ); $i++ ) {
					$cells[] = '';
				}
			}
			if ( '' !== trim( implode( '', $cells ) ) ) {
				$rows[] = implode( "\t", $cells );
			}
		}
		if ( $rows ) {
			$this->blocks[] = $rows; // a one-column table is just lines, which is what it reads as
		}
		$this->in_list = false;
		if ( $images ) {
			$this->notes['table_images'] = 'The Word file has images inside a table; they were placed after the table.';
			foreach ( $images as $img ) {
				$this->image_block( $img );
			}
		}
	}

	/** @return \DOMElement[] the row's cells, including ones wrapped in content controls */
	private function row_cells( \DOMElement $tr ): array {
		$out = array();
		foreach ( $tr->childNodes as $n ) {
			if ( ! $n instanceof \DOMElement || self::W !== $n->namespaceURI ) {
				continue;
			}
			if ( 'tc' === $n->localName ) {
				$out[] = $n;
			} elseif ( in_array( $n->localName, array( 'sdt', 'customXml' ), true ) ) {
				$inner = 'sdt' === $n->localName ? $this->first( $n, self::W, 'sdtContent' ) : $n;
				foreach ( $inner ? $this->children( $inner, self::W, 'tc' ) : array() as $tc ) {
					$out[] = $tc;
				}
			}
		}
		return $out;
	}

	/**
	 * Walk a paragraph's content in order, collecting text, images, text boxes.
	 *
	 * $state['fields'] is a stack of complex fields: each [phase, instruction],
	 * phase 'code' (between begin and separate: hidden) or 'result' (shown).
	 */
	private function inline( \DOMElement $el, array &$state ): void {
		foreach ( $el->childNodes as $n ) {
			if ( ! $n instanceof \DOMElement ) {
				continue;
			}
			$ns = $n->namespaceURI;
			$ln = $n->localName;
			if ( self::MC === $ns && 'AlternateContent' === $ln ) {
				$pick = $this->first( $n, self::MC, 'Choice' ) ?? $this->first( $n, self::MC, 'Fallback' );
				if ( $pick ) {
					$this->inline( $pick, $state );
				}
				continue;
			}
			if ( self::W !== $ns ) {
				continue;
			}
			$hidden = $this->in_field_code( $state );
			switch ( $ln ) {
				case 'pPr':
				case 'rPr':
				case 'del':
				case 'moveFrom':
				case 'commentReference':
				case 'annotationRef':
				case 'proofErr':
				case 'bookmarkStart':
				case 'bookmarkEnd':
					break;
				case 't':
					if ( ! $hidden ) {
						$state['text'] .= $n->textContent;
					}
					break;
				case 'tab':
					if ( ! $hidden ) {
						$state['text'] .= "\t";
					}
					break;
				case 'br':
				case 'cr':
					if ( ! $hidden ) {
						$state['text'] .= "\n";
					}
					break;
				case 'noBreakHyphen':
					$state['text'] .= $hidden ? '' : '-';
					break;
				case 'softHyphen':
					break;
				case 'sym':
					$this->notes['sym'] = 'The Word file uses symbol-font characters, which were left out. Check for missing symbols.';
					break;
				case 'instrText':
					if ( $state['fields'] ) {
						$state['fields'][ count( $state['fields'] ) - 1 ][1] .= $n->textContent;
					}
					break;
				case 'fldChar':
					$this->field_char( $n->getAttributeNS( self::W, 'fldCharType' ), $state );
					break;
				case 'fldSimple':
					$before = strlen( $state['text'] );
					$this->inline( $n, $state );
					$this->field_link( $n->getAttributeNS( self::W, 'instr' ), substr( $state['text'], $before ) );
					break;
				case 'hyperlink':
					$before = strlen( $state['text'] );
					$this->inline( $n, $state );
					$rid = $n->getAttributeNS( self::R, 'id' );
					if ( '' !== $rid && isset( $this->rels[ $rid ] ) && $this->rels[ $rid ]['external'] ) {
						$this->add_link( substr( $state['text'], $before ), $this->rels[ $rid ]['target'] );
					}
					break;
				case 'footnoteReference':
				case 'endnoteReference':
					$this->notes['notes'] = 'The Word file has footnotes or endnotes; they weren\'t imported. Add them to the text if they\'re needed.';
					break;
				case 'object':
					$this->notes['object'] = 'The Word file has embedded objects (charts, equations or files) that can\'t be imported.';
					break;
				case 'drawing':
					if ( ! $hidden ) {
						$this->drawing( $n, $state );
					}
					break;
				case 'pict':
					if ( ! $hidden ) {
						$this->pict( $n, $state );
					}
					break;
				case 'r':
				case 'ins':
				case 'moveTo':
				case 'smartTag':
				case 'customXml':
				case 'dir':
				case 'bdo':
					$this->inline( $n, $state );
					break;
				case 'sdt':
					$c = $this->first( $n, self::W, 'sdtContent' );
					if ( $c ) {
						$this->inline( $c, $state );
					}
					break;
			}
		}
	}

	private function in_field_code( array $state ): bool {
		foreach ( $state['fields'] as $f ) {
			if ( 'code' === $f[0] ) {
				return true;
			}
		}
		return false;
	}

	private function field_char( string $type, array &$state ): void {
		if ( 'begin' === $type ) {
			$state['fields'][] = array( 'code', '', strlen( $state['text'] ) );
		} elseif ( 'separate' === $type && $state['fields'] ) {
			$i                        = count( $state['fields'] ) - 1;
			$state['fields'][ $i ][0] = 'result';
			$state['fields'][ $i ][2] = strlen( $state['text'] );
		} elseif ( 'end' === $type && $state['fields'] ) {
			[ , $instr, $start ] = array_pop( $state['fields'] );
			$this->field_link( $instr, substr( $state['text'], $start ) );
		}
	}

	/** A HYPERLINK field's address, recorded with its displayed text. */
	private function field_link( string $instr, string $shown ): void {
		if ( preg_match( '/^\s*HYPERLINK\s+(?:\\\\[a-z]\s+)*"([^"]+)"/i', $instr, $m ) ) {
			$this->add_link( $shown, $m[1] );
		}
	}

	private function add_link( string $text, string $url ): void {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$url  = trim( $url );
		if ( '' === $text || ! preg_match( '#^(?:https?://|mailto:)#i', $url ) ) {
			return;
		}
		foreach ( $this->links as $l ) {
			if ( $l['text'] === $text && $l['url'] === $url ) {
				return;
			}
		}
		$this->links[] = array( 'text' => $text, 'url' => $url );
	}

	private function drawing( \DOMElement $d, array &$state ): void {
		$pr  = $d->getElementsByTagNameNS( self::WP, 'docPr' )->item( 0 );
		$alt = $pr instanceof \DOMElement ? trim( (string) preg_replace( '/\s+/u', ' ', $pr->getAttribute( 'descr' ) ?: $pr->getAttribute( 'title' ) ) ) : '';
		$blip = $d->getElementsByTagNameNS( self::A, 'blip' )->item( 0 );
		if ( $blip instanceof \DOMElement ) {
			$rid = $blip->getAttributeNS( self::R, 'embed' );
			if ( '' === $rid && '' !== $blip->getAttributeNS( self::R, 'link' ) ) {
				$this->notes['linked'] = 'The Word file has pictures linked from elsewhere instead of inserted; they couldn\'t be taken out. Upload those images separately.';
				return;
			}
			$state['images'][] = array( 'rid' => $rid, 'alt' => $alt );
		}
		// Text boxes (shapes with text) sit in the drawing too.
		foreach ( $d->getElementsByTagNameNS( self::W, 'txbxContent' ) as $box ) {
			$state['boxes'][] = $box;
		}
	}

	/** Older (VML) pictures and text boxes. */
	private function pict( \DOMElement $p, array &$state ): void {
		foreach ( $p->getElementsByTagNameNS( self::V, 'imagedata' ) as $img ) {
			$state['images'][] = array( 'rid' => $img->getAttributeNS( self::R, 'id' ), 'alt' => trim( (string) $img->getAttribute( 'o:title' ) ) );
		}
		foreach ( $p->getElementsByTagNameNS( self::W, 'txbxContent' ) as $box ) {
			$state['boxes'][] = $box;
		}
	}

	/** Take an image out of the package and add its "[IMAGE: …]" lines. */
	private function image_block( array $img ): void {
		$rel = $this->rels[ $img['rid'] ] ?? null;
		if ( ! $rel || $rel['external'] ) {
			return;
		}
		$path = $rel['target'];
		if ( ! isset( $this->images[ $path ] ) ) {
			$bytes = $this->zip->get( $path, Assets::MAX_IMAGE_BYTES );
			if ( null === $bytes ) {
				return;
			}
			$type = Assets::sniff_image( $bytes );
			if ( ! $type ) {
				$this->notes[ 'format:' . $path ] = 'Picture “' . basename( $path ) . '” in the Word file is in a format web pages can\'t show (' . strtoupper( pathinfo( $path, PATHINFO_EXTENSION ) ) . '). Save it as PNG or JPEG and upload it.';
				return;
			}
			$name                  = pathinfo( basename( $path ), PATHINFO_FILENAME ) . '.' . $type['ext'];
			$this->images[ $path ] = array( 'name' => $name, 'bytes' => $bytes, 'mime' => $type['mime'] );
		}
		$lines = array( '[IMAGE: ' . $this->images[ $path ]['name'] . ']' );
		if ( '' !== $img['alt'] ) {
			$lines[] = 'Alt text: ' . $img['alt'];
		}
		$this->blocks[]         = $lines;
		$this->last_image_block = count( $this->blocks ) - 1;
		$this->in_list          = false;
	}

	// ---------------------------------------------------------------- helpers

	/** Trim each line; drop characters Word uses for layout only. */
	private static function clean( string $text ): string {
		$text = str_replace( array( "\u{00AD}", "\u{200B}" ), '', $text );
		return implode( "\n", array_map( 'rtrim', explode( "\n", $text ) ) );
	}

	private function first( ?\DOMElement $el, string $ns, string $name ): ?\DOMElement {
		if ( ! $el ) {
			return null;
		}
		foreach ( $el->childNodes as $n ) {
			if ( $n instanceof \DOMElement && $n->namespaceURI === $ns && $n->localName === $name ) {
				return $n;
			}
		}
		return null;
	}

	/** @return \DOMElement[] */
	private function children( \DOMElement $el, string $ns, string $name ): array {
		$out = array();
		foreach ( $el->childNodes as $n ) {
			if ( $n instanceof \DOMElement && $n->namespaceURI === $ns && $n->localName === $name ) {
				$out[] = $n;
			}
		}
		return $out;
	}
}
