<?php
/**
 * Ingest, the structure map validator and table/image parsing.
 * Ported from the prototype's unit.test.js and blocks.test.js.
 */

use CPub\Publisher\Pipeline\Blocks;
use CPub\Publisher\Pipeline\Ingest;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\MarkdownWriter;
use CPub\Publisher\Pipeline\StructureMap;

class PipelineUnitTest extends PipelineTestCase {

	public function test_ingest_numbers_non_blank_lines_and_collapses_blank_runs() {
		$s = self::sample( '03-messy.txt' );
		$this->assertSame( 'The Hidden Cost of "Free" Software', $s['lines'][0]['text'] );
		$this->assertCount( 1, array_filter( $s['layout'], fn( $l ) => ! empty( $l['blank'] ) ) );
		$this->assertCount( 13, $s['lines'] );
	}

	public function test_decode_falls_back_to_windows_1252_and_handles_crlf() {
		$s = self::sample( '04-cp1252-crlf.txt' );
		$this->assertMatchesRegularExpression( '/1252/', $s['encoding'] );
		$this->assertSame( 'Café prices rose 10% & more <fast>.', $s['lines'][1]['text'] );
	}

	public function test_decode_handles_utf16_with_bom() {
		$this->assertSame( "Héllo\n", Ingest::decode( "\xFF\xFE" . mb_convert_encoding( "Héllo\n", 'UTF-16LE', 'UTF-8' ) )['text'] );
		$this->assertSame( "Héllo\n", Ingest::decode( "\xFE\xFF" . mb_convert_encoding( "Héllo\n", 'UTF-16BE', 'UTF-8' ) )['text'] );
		$this->assertSame( array( 'text' => 'abc', 'encoding' => 'utf-8' ), Ingest::decode( "\xEF\xBB\xBFabc" ) );
	}

	public function test_normalize_changes_nothing_but_whitespace_and_composition() {
		$this->assertSame( "a b\nc\n\nd", Ingest::normalize( "\n\na\u{00A0}b  \r\nc\t\r\n\r\nd\n\n" ) );
		if ( class_exists( \Normalizer::class ) ) {
			$this->assertSame( "Caf\u{00E9}", Ingest::normalize( "Cafe\u{0301}" ) ); // decomposed é becomes one character
		}
	}

	public function test_empty_input_is_refused() {
		$this->expectException( \InvalidArgumentException::class );
		Ingest::ingest( "  \n\t\n" );
	}

	public function test_prompt_shows_ids_blanks_and_tabs() {
		$s = Ingest::ingest( "Title\n\n\nName\tAge\n" );
		$this->assertSame( "L   1 | Title\n        (blank)\nL   2 | Name ⇥ Age", Ingest::render_for_prompt( $s['layout'] ) );
	}

	public function test_valid_map_passes() {
		$r = StructureMap::validate( self::MAP_02, self::sample( '02-hardwrapped.txt' )['lines'] );
		$this->assertTrue( $r['ok'], implode( '; ', $r['errors'] ) );
	}

	public function test_validator_catches_missing_duplicate_non_consecutive_out_of_order_out_of_range() {
		$lines                        = self::sample( '02-hardwrapped.txt' )['lines'];
		$broken                       = self::MAP_02;
		$broken['blocks'][0]['lines'] = array( 3, 5 );
		$broken['blocks'][6]['lines'] = array( 18, 99 );
		$broken['blocks'][]           = array( 'type' => 'paragraph', 'lines' => array( 7 ) );
		$r                            = StructureMap::validate( $broken, $lines );
		$this->assertFalse( $r['ok'] );
		$all = implode( "\n", $r['errors'] );
		$this->assertMatchesRegularExpression( '/consecutive/', $all );
		$this->assertMatchesRegularExpression( '/does not exist/', $all );
		$this->assertMatchesRegularExpression( '/used twice/', $all );
		$this->assertMatchesRegularExpression( '/reading order/', $all );
		$this->assertMatchesRegularExpression( '/not assigned anywhere.*\b4\b/', $all );
	}

	public function test_validator_rejects_schema_violations() {
		$lines = self::sample( '02-hardwrapped.txt' )['lines'];
		$bad   = array(
			array( 'blocks' => array( array( 'type' => 'heading', 'level' => 1, 'lines' => array( 'THE PROBLEM' ) ) ) ) + self::MAP_02, // h1, text instead of ids
			array( 'blocks' => array( array( 'type' => 'heading', 'level' => 1, 'lines' => array( 6 ) ) ) ) + self::MAP_02,
			array( 'blocks' => array( array( 'type' => 'poem', 'lines' => array( 3 ) ) ) ) + self::MAP_02,
			array( 'title' => '1' ) + self::MAP_02,
			array( 'excluded' => array( array( 'line' => 2, 'kind' => 'advert' ) ) ) + self::MAP_02,
			'not a map',
			null,
		);
		foreach ( $bad as $i => $map ) {
			$r = StructureMap::validate( $map, $lines );
			$this->assertFalse( $r['ok'], "case {$i}" );
			$this->assertNotEmpty( $r['errors'], "case {$i}" );
		}
	}

	public function test_model_schema_has_no_property_named_items() {
		$offenders = array();
		$walk      = function ( $node, string $path ) use ( &$walk, &$offenders ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['properties'] ) && is_array( $node['properties'] ) && array_key_exists( 'items', $node['properties'] ) ) {
				$offenders[] = $path;
			}
			foreach ( $node as $k => $v ) {
				$walk( $v, "{$path}.{$k}" );
			}
		};
		$walk( StructureMap::schema(), '$' );
		$this->assertSame( array(), $offenders );
	}

	public function test_model_schema_requires_list_items_on_list_blocks() {
		$list = current( array_filter( StructureMap::schema()['properties']['blocks']['items']['anyOf'], fn( $v ) => 'List' === ( $v['title'] ?? '' ) ) );
		$this->assertContains( 'list_items', $list['required'] );
	}

	public function test_schema_and_prompts_are_identical_to_the_prototype() {
		// Recorded from the prototype; a change here changes what the model is asked for.
		$schema = json_decode( (string) file_get_contents( self::fixture( 'structure-schema.json' ) ), true );
		$this->assertSame( $schema, StructureMap::schema() );
		$prompts = json_decode( (string) file_get_contents( self::fixture( 'prompts.json' ) ), true );
		$this->assertSame( $prompts['system'], \CPub\Publisher\Pipeline\Annotator::SYSTEM );
		$this->assertSame( $prompts['revise'], \CPub\Publisher\Pipeline\Annotator::REVISE );
	}

	public function test_validator_accepts_the_model_format() {
		$lines = self::sample( '02-hardwrapped.txt' )['lines'];
		$model = StructureMap::to_model( self::MAP_02 );
		$this->assertArrayHasKey( 'list_items', $model['blocks'][4] );
		$this->assertArrayNotHasKey( 'items', $model['blocks'][4] );
		$r = StructureMap::validate( $model, $lines );
		$this->assertTrue( $r['ok'], implode( '; ', $r['errors'] ) );
		$this->assertSame( array( array( 11 ), array( 12 ), array( 13, 14 ) ), $r['map']['blocks'][4]['items'] );
		$this->assertSame( array(), $r['warnings'] );
	}

	public function test_a_list_returned_as_flat_lines_is_salvaged_with_a_warning() {
		$lines            = self::sample( '02-hardwrapped.txt' )['lines'];
		$m                = self::MAP_02;
		$m['blocks'][4]   = array( 'type' => 'list', 'ordered' => true, 'lines' => array( 11, 12, 13, 14 ) );
		$r                = StructureMap::validate( $m, $lines );
		$this->assertTrue( $r['ok'], implode( '; ', $r['errors'] ) );
		$this->assertSame( array( array( 11 ), array( 12 ), array( 13 ), array( 14 ) ), $r['map']['blocks'][4]['items'] );
		$this->assertMatchesRegularExpression( '/one item per line/', implode( ' ', $r['warnings'] ) );
	}

	public function test_a_list_with_no_items_still_fails() {
		$m              = self::MAP_02;
		$m['blocks'][4] = array( 'type' => 'list', 'ordered' => true );
		$this->assertFalse( StructureMap::validate( $m, self::sample( '02-hardwrapped.txt' )['lines'] )['ok'] );
	}

	// ---- tables and images (blocks.test.js) ----

	public function test_tab_tables_split_on_tabs_and_pad_uneven_rows() {
		$t = Blocks::parse_table( self::lines( "Name\tAge\tCity", "Ann\t30", "Bob\t41\tMuscat" ) );
		$this->assertSame( 'tab', $t['format'] );
		$this->assertSame( array( array( 'Name', 'Age', 'City' ), array( 'Ann', '30', '' ), array( 'Bob', '41', 'Muscat' ) ), $t['rows'] );
		$this->assertTrue( $t['ragged'] );
	}

	public function test_pipe_tables_separator_implies_header_and_escaped_pipes_stay() {
		$t = Blocks::parse_table( self::lines( '| a | b \\| c |', '|:---|---:|', '| 1 | 2 |' ) );
		$this->assertTrue( $t['header'] );
		$this->assertSame( array( array( 'a', 'b | c' ), array( '1', '2' ) ), $t['rows'] );
		$this->assertNull( Blocks::parse_table( self::lines( '| 1 | 2 |', '| 3 | 4 |' ) )['header'] );
	}

	public function test_space_aligned_or_single_column_lines_are_not_tables() {
		$this->assertFalse( Blocks::parse_table( self::lines( 'Name     Age', 'Ann      30' ) )['ok'] );
		$this->assertFalse( Blocks::parse_table( self::lines( '| only |', '| one |' ) )['ok'] );
		$this->assertFalse( Blocks::parse_table( self::lines( '| a | b |', 'not a row' ) )['ok'] );
	}

	public function test_images_fields_in_any_order_unknown_lines_and_duplicates_rejected() {
		$img = Blocks::parse_image( self::lines( 'Alt text: A cup', 'Image URL: https://e.com/a.jpg', '[IMAGE: cup.jpg]' ) );
		$this->assertSame( array( 'ok' => true, 'filename' => 'cup.jpg', 'url' => 'https://e.com/a.jpg', 'alt' => 'A cup', 'caption' => '', 'credit' => '' ), $img );
		$this->assertMatchesRegularExpression( '/isn\'t part of an image/', Blocks::parse_image( self::lines( '[IMAGE: a.jpg]', 'Some sentence' ) )['error'] );
		$this->assertMatchesRegularExpression( '/two/', Blocks::parse_image( self::lines( 'Image URL: https://e.com/a', 'Image URL: https://e.com/b' ) )['error'] );
		$this->assertMatchesRegularExpression( '/no \[IMAGE/', Blocks::parse_image( self::lines( 'Caption: only a caption' ) )['error'] );
	}

	public function test_validator_sends_back_bad_table_guesses_and_image_lines_left_as_paragraphs() {
		$lines = self::lines( 'Title', 'Name     Age', 'Ann      30', 'Image URL: https://e.com/a.jpg' );
		$r     = StructureMap::validate(
			array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'table', 'header' => true, 'lines' => array( 2, 3 ) ), array( 'type' => 'paragraph', 'lines' => array( 4 ) ) ) ),
			$lines
		);
		$this->assertFalse( $r['ok'] );
		$this->assertMatchesRegularExpression( '/can\'t be split into columns/', implode( "\n", $r['errors'] ) );
		$this->assertMatchesRegularExpression( '/describe an image/', implode( "\n", $r['errors'] ) );
	}

	public function test_warnings_for_missing_alt_text_and_file_only_images_and_sending_is_blocked() {
		$lines = self::lines( 'Title', '[IMAGE: hero.jpg]' );
		$r     = StructureMap::validate( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'image', 'lines' => array( 2 ) ) ) ), $lines );
		$this->assertTrue( $r['ok'] );
		$this->assertMatchesRegularExpression( '/no Image URL/', implode( "\n", $r['warnings'] ) );
		$this->assertMatchesRegularExpression( '/no alt text/', implode( "\n", $r['warnings'] ) );
		$md = MarkdownWriter::from_map( $r['map'], $lines )['markdown'];
		$this->assertMatchesRegularExpression( '/needs a web address/', MarkdownToPost::convert( $md )['errors'][0]['message'] );
	}

	public function test_source_line_as_an_ordinary_paragraph_is_allowed() {
		$lines = self::lines( 'T', 'Figures from 2025.', 'Source: Reuters' );
		$r     = StructureMap::validate( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'paragraph', 'lines' => array( 2 ) ), array( 'type' => 'paragraph', 'lines' => array( 3 ) ) ) ), $lines );
		$this->assertTrue( $r['ok'], implode( "\n", $r['errors'] ) );
	}
}
