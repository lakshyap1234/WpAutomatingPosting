<?php
/**
 * The .docx reader: realistic files from Word-compatible tools, and hand-built
 * edge cases (fields, tracked changes, hostile packages).
 */

use CPub\Publisher\Intake\Docx;
use CPub\Publisher\Intake\IntakeException;

class DocxTest extends WP_UnitTestCase {

	private const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:v="urn:schemas-microsoft-com:vml"';

	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	private static function fixture( string $name ): string {
		return (string) file_get_contents( __DIR__ . '/fixtures/docx/' . $name );
	}

	/**
	 * A minimal .docx around a body.
	 *
	 * @param array<string,string> $parts extra parts (path => content)
	 * @param string               $rels  extra document relationships
	 */
	private static function build( string $body, array $parts = array(), string $rels = '', ?string $numbering = null ): string {
		$tmp = wp_tempnam( 'docx-test' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>' );
		$zip->addFromString( 'word/document.xml', '<?xml version="1.0"?><w:document ' . self::NS . '><w:body>' . $body . '</w:body></w:document>' );
		if ( null !== $numbering ) {
			$zip->addFromString( 'word/numbering.xml', '<?xml version="1.0"?><w:numbering ' . self::NS . '>' . $numbering . '</w:numbering>' );
			$rels .= '<Relationship Id="rNum" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>';
		}
		$zip->addFromString( 'word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>' );
		foreach ( $parts as $path => $content ) {
			$zip->addFromString( $path, $content );
		}
		$zip->close();
		$bytes = (string) file_get_contents( $tmp );
		unlink( $tmp );
		return $bytes;
	}

	private static function p( string $inner, string $ppr = '' ): string {
		return '<w:p>' . ( '' !== $ppr ? "<w:pPr>{$ppr}</w:pPr>" : '' ) . $inner . '</w:p>';
	}

	private static function r( string $text ): string {
		return '<w:r><w:t xml:space="preserve">' . htmlspecialchars( $text, ENT_XML1 ) . '</w:t></w:r>';
	}

	private static function image_run( string $rid, string $descr ): string {
		return '<w:r><w:drawing><wp:inline><wp:docPr id="1" name="Picture 1" descr="' . htmlspecialchars( $descr, ENT_XML1 ) . '"/><a:graphic><a:graphicData><a:blip r:embed="' . $rid . '"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
	}

	// ---- realistic files ----

	public function test_word_file_from_python_docx() {
		$r = Docx::read( self::fixture( 'coffee.docx' ) );
		$this->assertStringStartsWith( "How to Choose a Home Coffee Grinder\n\nBy Sara Al-Harthy\n\nIf you’ve ever wondered", $r['text'] );
		$this->assertStringContainsString( "- Conical burrs: quieter, slower\n- Flat burrs: more uniform grind\n", $r['text'] );
		$this->assertStringContainsString( "Method\tGrind\tTime\nEspresso\tFine\t25-30 sec\nFrench press\tCoarse\t4 min\n", $r['text'] );
		$this->assertStringContainsString( "[IMAGE: image1.png]\nAlt text: A burr grinder next to a bag of beans\nCaption: A good burr grinder is the foundation of better coffee.\n", $r['text'] );
		$this->assertStringContainsString( "1. Weigh the beans\n2. Grind just before brewing\n3. Clean the burrs monthly\n", $r['text'] );
		$this->assertStringContainsString( "Read our espresso guide for more.\n\nOffice 204\nWay 3311\nMuscat\n", $r['text'] );
		$this->assertSame( array( array( 'text' => 'espresso guide', 'url' => 'https://example.com/espresso-guide' ) ), $r['links'] );
		$this->assertCount( 1, $r['images'] );
		$this->assertSame( 'image/png', $r['images'][0]['mime'] );
		$this->assertSame( array(), $r['notes'] );
	}

	public function test_without_zip_archive_the_builtin_reader_reads_the_same() {
		\CPub\Publisher\Intake\ZipReader::$force_builtin = true;
		try {
			$this->assertSame( Docx::read( self::fixture( 'coffee.docx' ) )['text'], Docx::read( self::fixture( 'coffee-libreoffice.docx' ) )['text'] );
			$this->assertCount( 1, Docx::read( self::fixture( 'coffee.docx' ) )['images'] );
		} finally {
			\CPub\Publisher\Intake\ZipReader::$force_builtin = false;
		}
		$this->assertStringContainsString( 'Burr grinders', Docx::read( self::fixture( 'coffee.docx' ) )['text'] );
	}

	public function test_same_file_saved_by_libreoffice_reads_the_same() {
		$this->assertSame( Docx::read( self::fixture( 'coffee.docx' ) )['text'], Docx::read( self::fixture( 'coffee-libreoffice.docx' ) )['text'] );
	}

	public function test_word_file_from_pandoc() {
		$r = Docx::read( self::fixture( 'ai-pandoc.docx' ) );
		$this->assertStringContainsString( "1. Medicine: reading scans\n2. Science: predicting protein structures\n3. Everyday life: translation\n", $r['text'] );
		$this->assertStringContainsString( "Area\tExample\nHealth\tScan triage\n", $r['text'] );
		$this->assertStringContainsString( "Alt text: Diagram of a neural network\nCaption: Diagram of a neural network\n", $r['text'] );
		$this->assertStringContainsString( 'This post looks at what AI actually is and where it is already helping.', $r['text'] ); // formatting dropped, words kept
		$this->assertSame( 'https://alphafold.ebi.ac.uk', $r['links'][0]['url'] );
		$this->assertStringContainsString( 'lists inside lists', implode( ' ', $r['notes'] ) );
	}

	public function test_word_file_through_the_pipeline() {
		$map = array(
			'title'    => 1,
			'excluded' => array( array( 'line' => 2, 'kind' => 'byline' ), array( 'line' => 23, 'kind' => 'metadata' ) ),
			'blocks'   => array(
				array( 'type' => 'paragraph', 'lines' => array( 3 ) ),
				array( 'type' => 'heading', 'level' => 2, 'lines' => array( 4 ) ),
				array( 'type' => 'paragraph', 'lines' => array( 5 ) ),
				array( 'type' => 'list', 'ordered' => false, 'items' => array( array( 6 ), array( 7 ) ) ),
				array( 'type' => 'heading', 'level' => 2, 'lines' => array( 8 ) ),
				array( 'type' => 'table', 'header' => true, 'lines' => array( 9, 10, 11 ) ),
				array( 'type' => 'image', 'lines' => array( 12, 13, 14 ) ),
				array( 'type' => 'heading', 'level' => 2, 'lines' => array( 15 ) ),
				array( 'type' => 'list', 'ordered' => true, 'items' => array( array( 16 ), array( 17 ), array( 18 ) ) ),
				array( 'type' => 'paragraph', 'lines' => array( 19 ) ),
				array( 'type' => 'paragraph', 'breaks' => true, 'lines' => array( 20, 21, 22 ) ),
			),
		);
		$r   = ( new \CPub\Publisher\Pipeline\Pipeline() )->run( self::fixture( 'coffee.docx' ), 'coffee.docx', $map );
		$this->assertSame( 'docx', $r['encoding'] );
		$this->assertSame( 'How to Choose a Home Coffee Grinder', $r['title'] );
		$this->assertStringContainsString( "\n- Conical burrs: quieter, slower\n- Flat burrs: more uniform grind\n", $r['markdown'] );
		$this->assertStringContainsString( '![A burr grinder next to a bag of beans](image1.png "A good burr grinder is the foundation of better coffee.")', $r['markdown'] );
		$this->assertSame( array( 'image1.png' => 'image1.png' ), $r['image_names'] );
		$this->assertCount( 1, $r['docx']['images'] );
		$this->assertMatchesRegularExpression( '/links “espresso guide” to https:\/\/example\.com\/espresso-guide.*\[espresso guide\]\(https:\/\/example\.com\/espresso-guide\)/', implode( "\n", $r['warnings'] ) );
		// The only problem left for the reviewer: the image has no web address yet (uploads are matched later).
		$this->assertSame( 1, count( $r['post']['errors'] ) );
	}

	// ---- edge cases ----

	public function test_tracked_changes_are_read_as_accepted_and_fields_show_their_result() {
		$body  = self::p( self::r( 'Price ' ) . '<w:del><w:r><w:delText>was 10</w:delText></w:r></w:del><w:ins>' . self::r( 'is 12' ) . '</w:ins>' . self::r( ' rials.' ) );
		$body .= self::p( self::r( 'Page ' ) . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGE </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>' . self::r( '3' ) . '<w:r><w:fldChar w:fldCharType="end"/></w:r>' );
		$body .= self::p( self::r( 'See ' ) . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> HYPERLINK "https://example.com/x" \o "tip" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r>' . self::r( 'this page' ) . '<w:r><w:fldChar w:fldCharType="end"/></w:r>' );
		$body .= self::p( '<w:fldSimple w:instr=" HYPERLINK &quot;https://example.org/&quot; ">' . self::r( 'simple link' ) . '</w:fldSimple>' );
		$body .= self::p( '<w:sdt><w:sdtContent>' . self::r( 'In a content control' ) . '</w:sdtContent></w:sdt>' );
		$body .= self::p( '<mc:AlternateContent><mc:Choice Requires="wps">' . self::r( 'Chosen' ) . '</mc:Choice><mc:Fallback>' . self::r( 'Fallback' ) . '</mc:Fallback></mc:AlternateContent>' );
		$body .= self::p( self::r( 'Tab' ) . '<w:r><w:tab/></w:r>' . self::r( 'after, no' ) . '<w:r><w:noBreakHyphen/></w:r>' . self::r( 'break, soft' ) . '<w:r><w:softHyphen/></w:r>' . self::r( 'hyphen' ) );
		$r     = Docx::read( self::build( $body ) );
		$this->assertSame( "Price is 12 rials.\n\nPage 3\n\nSee this page\n\nsimple link\n\nIn a content control\n\nChosen\n\nTab\tafter, no-break, softhyphen\n", $r['text'] );
		$this->assertSame(
			array( array( 'text' => 'this page', 'url' => 'https://example.com/x' ), array( 'text' => 'simple link', 'url' => 'https://example.org/' ) ),
			$r['links']
		);
	}

	public function test_numbering_restarts_and_numid_zero() {
		$numbering = '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:start w:val="5"/><w:numFmt w:val="decimal"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="lowerLetter"/></w:lvl></w:abstractNum>'
			. '<w:num w:numId="7"><w:abstractNumId w:val="1"/></w:num>';
		$item      = fn( $t, $lvl = 0, $id = 7 ) => self::p( self::r( $t ), '<w:numPr><w:ilvl w:val="' . $lvl . '"/><w:numId w:val="' . $id . '"/></w:numPr>' );
		$body      = $item( 'five' ) . $item( 'sub a', 1 ) . $item( 'sub b', 1 ) . $item( 'six' ) . $item( 'sub again', 1 ) . self::p( self::r( 'para' ) ) . $item( 'not a list', 0, 0 );
		$r         = Docx::read( self::build( $body, array(), '', $numbering ) );
		$this->assertSame( "5. five\n1. sub a\n2. sub b\n6. six\n1. sub again\n\npara\n\nnot a list\n", $r['text'] );
	}

	public function test_tables_with_merged_cells_and_images() {
		$cell  = fn( $t, $pr = '' ) => '<w:tc>' . ( $pr ? "<w:tcPr>{$pr}</w:tcPr>" : '' ) . self::p( self::r( $t ) ) . '</w:tc>';
		$body  = '<w:tbl>'
			. '<w:tr>' . $cell( 'Wide', '<w:gridSpan w:val="2"/>' ) . $cell( 'C' ) . '</w:tr>'
			. '<w:tr>' . $cell( 'Top', '<w:vMerge w:val="restart"/>' ) . $cell( 'x	y' ) . $cell( 'z' ) . '</w:tr>'
			. '<w:tr>' . $cell( '', '<w:vMerge/>' ) . '<w:tc>' . self::p( self::r( 'two' ) ) . self::p( self::r( 'paras' ) ) . '</w:tc>' . '<w:tc>' . self::p( self::image_run( 'rImg', 'Cell pic' ) ) . '</w:tc></w:tr>'
			. '</w:tbl>';
		$r     = Docx::read( self::build( $body, array( 'word/media/pic.png' => base64_decode( self::PNG ) ), '<Relationship Id="rImg" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/pic.png"/>' ) );
		$this->assertSame( "Wide\t\tC\nTop\tx y\tz\n\ttwo paras\t\n\n[IMAGE: pic.png]\nAlt text: Cell pic\n", $r['text'] );
		$this->assertStringContainsString( 'inside a table', implode( ' ', $r['notes'] ) );
	}

	public function test_unsupported_images_linked_pictures_footnotes_and_text_boxes_are_reported() {
		$rels  = '<Relationship Id="rEmf" Type="x/image" Target="media/logo.emf"/>';
		$body  = self::p( self::image_run( 'rEmf', 'Logo' ) );
		$body .= self::p( '<w:r><w:drawing><wp:inline><wp:docPr id="2" name="x"/><a:graphic><a:graphicData><a:blip r:link="rExt"/></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>' );
		$body .= self::p( self::r( 'Claim' ) . '<w:r><w:footnoteReference w:id="1"/></w:r>' );
		$body .= self::p( self::r( 'Before box' ) . '<w:r><w:pict><v:shape><v:textbox><w:txbxContent>' . self::p( self::r( 'Boxed text' ) ) . '</w:txbxContent></v:textbox></v:shape></w:pict></w:r>' );
		$r     = Docx::read( self::build( $body, array( 'word/media/logo.emf' => "\x01\x00\x00\x00EMF-not-an-image" ), $rels ) );
		$this->assertSame( "Claim\n\nBefore box\n\nBoxed text\n", $r['text'] );
		$notes = implode( "\n", $r['notes'] );
		$this->assertStringContainsString( '“logo.emf” in the Word file is in a format web pages can\'t show (EMF)', $notes );
		$this->assertStringContainsString( 'linked from elsewhere', $notes );
		$this->assertStringContainsString( 'footnotes', $notes );
		$this->assertStringContainsString( 'text boxes', $notes );
		$this->assertSame( array(), $r['images'] );
	}

	public function test_hostile_or_broken_files_are_refused_clearly() {
		$cases = array(
			'not a zip'   => array( 'plain text pretending', 'valid Word' ),
			'old .doc'    => array( "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1rest", 'save it as .docx' ),
			'no document' => array( ( function () {
				$tmp = wp_tempnam( 'x' );
				$z   = new ZipArchive();
				$z->open( $tmp, ZipArchive::OVERWRITE );
				$z->addFromString( 'hello.txt', 'hi' );
				$z->close();
				$b = file_get_contents( $tmp );
				unlink( $tmp );
				return $b;
			} )(), 'no document text' ),
			'doctype'     => array( self::build_raw( '<?xml version="1.0"?><!DOCTYPE lol [<!ENTITY a "aaaa">]><w:document ' . self::NS . '><w:body><w:p><w:r><w:t>&a;</w:t></w:r></w:p></w:body></w:document>' ), 'document type declaration' ),
			'broken xml'  => array( self::build_raw( '<?xml version="1.0"?><w:document ' . self::NS . '><w:body><w:p>' ), 'damaged' ),
			'empty'       => array( self::build( self::p( '' ) ), 'no text' ),
			'huge part'   => array( self::build_raw( '<?xml version="1.0"?><w:document ' . self::NS . '><w:body>' . str_repeat( '<w:p/>', 3600000 ) . '</w:body></w:document>' ), 'too large' ),
		);
		foreach ( $cases as $label => [ $bytes, $message ] ) {
			try {
				Docx::read( $bytes );
				$this->fail( "{$label}: expected refusal" );
			} catch ( IntakeException $e ) {
				$this->assertStringContainsString( $message, $e->getMessage(), $label );
			}
		}
	}

	private static function build_raw( string $document_xml ): string {
		$tmp = wp_tempnam( 'docx-test' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );
		$zip->addFromString( 'word/document.xml', $document_xml );
		$zip->close();
		$bytes = (string) file_get_contents( $tmp );
		unlink( $tmp );
		return $bytes;
	}

	public function test_file_size_limit() {
		$this->expectException( IntakeException::class );
		$this->expectExceptionMessage( '25 MB' );
		Docx::read( "PK\x03\x04" . str_repeat( 'x', Docx::MAX_FILE_BYTES ) );
	}
}
