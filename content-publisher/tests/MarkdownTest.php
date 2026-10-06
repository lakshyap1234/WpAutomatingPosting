<?php
/**
 * Markdown writer and Markdown → post converter.
 * Ported from the prototype's markdown.test.js and blocks.test.js, plus port-specific cases.
 */

use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\MarkdownWriter;
use CPub\Publisher\Pipeline\StructureMap;

class MarkdownTest extends PipelineTestCase {

	/** Plain text that looks like Markdown. Every line must survive untouched. */
	private const TRICKY = array(
		'Tricky *Title* with # and [brackets]',
		'2026. A big year for us',
		'# of users grew 40%',
		'10*5 = 50 and snake_case_name and __init__',
		'- not a list',
		'+ not a list either',
		'* not a bullet',
		'> not a quote',
		'---',
		'===',
		'~~~ not a fence',
		'&copy; and &#169; stay literal, but AT&T is fine',
		'Backslash \\ in the middle and at the end \\',
		'<b>not bold</b> and <script>alert(1)</script>',
		'[not a link](https://example.com) and ![not an image](x.png)',
		'Code-looking `backticks` stay',
		'1) not a list',
		'Link www.example.org/save_energy_now. and https://x.io/a_b_(c)).',
		'Bare example.com and Node.js stay plain',
		'Ends with hashes ##',
	);

	public function test_tricky_plain_text_survives_markdown_generation_and_parsing_exactly() {
		$lines  = self::lines( ...self::TRICKY );
		$blocks = array_map( fn( $l ) => array( 'type' => 'paragraph', 'lines' => array( $l['id'] ) ), array_slice( $lines, 1 ) );
		[ , $post ] = $this->round_trip( array( 'title' => 1, 'excluded' => array(), 'blocks' => $blocks ), $lines );
		$this->assertSame( self::TRICKY[0], $post['title'] );
		$this->assertSame( count( self::TRICKY ) - 1, $post['counts']['paragraphs'] );
		$this->assertDoesNotMatchRegularExpression( '/<script|<b>|<img|<h1|<blockquote|<hr|<code/', $post['content'] );
		$this->assertMatchesRegularExpression( '#<a href="https://www\.example\.org/save_energy_now">www\.example\.org/save_energy_now</a>\.#', $post['content'] );
		$this->assertMatchesRegularExpression( '#<a href="https://x\.io/a_b_\(c\)">#', $post['content'] );
		$this->assertDoesNotMatchRegularExpression( '#href="http://example\.com"#', $post['content'] );
	}

	public function test_tricky_text_inside_list_items_and_headings_survives() {
		$lines = self::lines( 'T', '1. starts like a number', '- dash item text', 'Heading with *stars* #1', 'para' );
		$map   = array(
			'title'    => 1,
			'excluded' => array(),
			'blocks'   => array(
				array( 'type' => 'list', 'ordered' => false, 'items' => array( array( 2 ), array( 3 ) ) ),
				array( 'type' => 'heading', 'level' => 3, 'lines' => array( 4 ) ),
				array( 'type' => 'paragraph', 'lines' => array( 5 ) ),
			),
		);
		$post = MarkdownToPost::convert( MarkdownWriter::from_map( $map, $lines )['markdown'] );
		$this->assertSame( array(), $post['errors'] );
		// Source list markers are stripped (the one intended change); the rest is literal.
		$this->assertStringContainsString( '<li>starts like a number</li>', $post['content'] );
		$this->assertStringContainsString( '<li>dash item text</li>', $post['content'] );
		$this->assertStringContainsString( '<h3 class="wp-block-heading">Heading with *stars* #1</h3>', $post['content'] );
	}

	public function test_sample_post_markdown_looks_as_expected_and_round_trips() {
		$lines                  = self::sample( '02-hardwrapped.txt' )['lines'];
		$map                    = StructureMap::validate( self::MAP_02, $lines )['map'];
		[ $doc, $post ]         = $this->round_trip( $map, $lines );
		$md                     = $doc['markdown'];
		$this->assertSame( '# WHY OUR TEAM SWITCHED TO WEEKLY PLANNING', explode( "\n", $md )[0] );
		$this->assertStringContainsString( "\n## THE PROBLEM\n", $md );
		$this->assertMatchesRegularExpression( '/\n1\. Every Monday, a 30-minute planning call\.\n2\. /', $md );
		$this->assertStringContainsString( "\n3. Friday, a short written recap of what shipped and what slipped, and why.\n", $md );
		$this->assertSame( array( 'Published 3 March 2026' ), array_column( $doc['removed'], 'text' ) );
		$this->assertSame( array( 'headings' => 2, 'paragraphs' => 4, 'lists' => 1, 'tables' => 0, 'images' => 0 ), $post['counts'] );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->' . "\n" . '<ol class="wp-block-list">', $post['content'] );
		$this->assertStringContainsString( '<a href="https://www.example.com/weekly-template">www.example.com/weekly-template</a>.', $post['content'] );
		$this->assertStringNotContainsString( 'Published 3 March', $post['content'] );
	}

	public function test_address_style_paragraphs_keep_line_breaks() {
		$lines = self::lines( 'T', 'Office 204', 'Way 3311', 'Muscat' );
		$md    = MarkdownWriter::from_map( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'paragraph', 'breaks' => true, 'lines' => array( 2, 3, 4 ) ) ) ), $lines )['markdown'];
		$this->assertStringContainsString( "Office 204\\\nWay 3311\\\nMuscat", $md );
		$this->assertStringContainsString( '<p>Office 204<br>Way 3311<br>Muscat</p>', MarkdownToPost::convert( $md )['content'] );
	}

	public function test_consecutive_lists_stay_separate() {
		$lines = self::lines( 'T', 'a', 'b', 'c', 'd' );
		$map   = array(
			'title'    => 1,
			'excluded' => array(),
			'blocks'   => array(
				array( 'type' => 'list', 'ordered' => false, 'items' => array( array( 2 ), array( 3 ) ) ),
				array( 'type' => 'list', 'ordered' => false, 'items' => array( array( 4 ), array( 5 ) ) ),
			),
		);
		[ , $post ] = $this->round_trip( $map, $lines );
		$this->assertSame( 2, $post['counts']['lists'] );
	}

	public function test_user_formatting_bold_italic_links_numbered_start() {
		$post = MarkdownToPost::convert( "# My post\n\nSome **bold**, *italic* and [a link](https://example.com).\n\n3. three\n4. four\n" );
		$this->assertSame( array(), $post['errors'] );
		$this->assertStringContainsString( '<strong>bold</strong>, <em>italic</em> and <a href="https://example.com">a link</a>', $post['content'] );
		$this->assertStringContainsString( '<!-- wp:list {"ordered":true,"start":3} -->' . "\n" . '<ol start="3" class="wp-block-list">', $post['content'] );
	}

	public function test_unsafe_links_are_not_rendered_as_links() {
		foreach ( array( 'javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,x', 'vbscript:x' ) as $href ) {
			$post = MarkdownToPost::convert( "# T\n\n[click]({$href})\n" );
			$this->assertDoesNotMatchRegularExpression( '/href="(?:javascript|data|vbscript)/i', $post['content'], $href );
		}
	}

	public function test_raw_html_is_never_parsed() {
		$post = MarkdownToPost::convert( "# T\n\n<div onclick=\"x\">hi</div>\n\ntext <img src=x onerror=alert(1)> more\n" );
		$this->assertStringNotContainsString( '<div', $post['content'] );
		$this->assertStringNotContainsString( '<img', $post['content'] );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $post['content'] );
	}

	/**
	 * @dataProvider error_cases
	 */
	public function test_clear_errors_for_unsupported_or_invalid_markdown( string $src, string $re ) {
		$errors = MarkdownToPost::convert( $src )['errors'];
		$this->assertTrue( (bool) array_filter( $errors, fn( $e ) => preg_match( $re, $e['message'] ) ), wp_json_encode( $errors ) );
	}

	public static function error_cases(): array {
		return array(
			'no title'      => array( "No title here\n", '/start with the title/' ),
			'second h1'     => array( "# A\n\n# B\n", '/Only the first line can be the title/' ),
			'h5'            => array( "# A\n\n##### deep\n", '/too deep/' ),
			'quote'         => array( "# A\n\n> quote\n", '/Quotes/' ),
			'fence'         => array( "# A\n\n```\ncode\n```\n", '/Code blocks/' ),
			'indented code' => array( "# A\n\n    indented\n", '/Indented code/' ),
			'hr'            => array( "# A\n\ntext\n\n---\n", '/Horizontal lines/' ),
			'image no url'  => array( "# A\n\n![img](x.png)\n", '/needs a web address/' ),
			'inline image'  => array( "# A\n\ntext ![img](https://e.com/x.png) text\n", '/its own line/' ),
			'nested list'   => array( "# A\n\n- a\n  - nested\n", '/Lists inside lists/' ),
			'no body'       => array( "# A\n", '/no body text/' ),
			'placeholder'   => array( "# T\n\n![Describe the image](https:// \"Optional caption\")\n", '/placeholder address/' ),
		);
	}

	public function test_errors_carry_line_numbers() {
		$this->assertSame( 5, MarkdownToPost::convert( "# A\n\nfine\n\n> quote\n" )['errors'][0]['line'] );
	}

	public function test_headerless_tables_round_trip_without_a_header_row() {
		$lines      = self::lines( 'Title', "a\tb", "c\td" );
		$map        = StructureMap::validate( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'table', 'header' => false, 'lines' => array( 2, 3 ) ) ) ), $lines )['map'];
		[ , $post ] = $this->round_trip( $map, $lines );
		$this->assertStringNotContainsString( '<thead>', $post['content'] );
		$this->assertStringContainsString( '<tbody><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></tbody>', $post['content'] );
	}

	public function test_table_cells_with_markdown_looking_text_survive_exactly() {
		$lines      = self::lines( 'Title', "Price\tNote", "10*5\t| pipes | and [brackets] and #1", "2026.\t<b>not bold</b>" );
		$map        = StructureMap::validate( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'table', 'header' => true, 'lines' => array( 2, 3, 4 ) ) ) ), $lines )['map'];
		[ , $post ] = $this->round_trip( $map, $lines );
		$this->assertStringContainsString( '<td>| pipes | and [brackets] and #1</td>', $post['content'] );
		$this->assertStringContainsString( '<td>&lt;b&gt;not bold&lt;/b&gt;</td>', $post['content'] );
	}

	public function test_image_alt_text_and_captions_with_quotes_brackets_and_backslashes_survive() {
		$lines      = self::lines( 'Title', '[IMAGE: a.jpg]', 'Image URL: https://e.com/a.jpg', 'Alt text: A "quoted" *star* [x] \\ slash', 'Caption: Say "hi" \\ bye' );
		$map        = StructureMap::validate( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'image', 'lines' => array( 2, 3, 4, 5 ) ) ) ), $lines )['map'];
		[ , $post ] = $this->round_trip( $map, $lines );
		$this->assertSame(
			array( array( 'A "quoted" *star* [x] \\ slash', 'Say "hi" \\ bye' ) ),
			array_map( fn( $i ) => array( $i['alt'], $i['caption'] ), $post['images'] )
		);
	}

	public function test_a_lone_plus_line_is_not_turned_into_a_nested_list() {
		// The prototype turned this into an empty nested list; the port escapes it.
		$lines      = self::lines( 'T', '+', '-', '*' );
		$blocks     = array_map( fn( $id ) => array( 'type' => 'paragraph', 'lines' => array( $id ) ), array( 2, 3, 4 ) );
		[ , $post ] = $this->round_trip( array( 'title' => 1, 'excluded' => array(), 'blocks' => $blocks ), $lines );
		$this->assertSame( 0, $post['counts']['lists'] );
	}

	public function test_html_format_has_no_block_comments_and_blocks_format_does() {
		$md = "# T\n\nHello\n\n## H\n\n- a\n";
		$this->assertStringNotContainsString( '<!-- wp:', MarkdownToPost::convert( $md, array( 'format' => 'html' ) )['content'] );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', MarkdownToPost::convert( $md, array( 'format' => 'blocks' ) )['content'] );
	}
}
