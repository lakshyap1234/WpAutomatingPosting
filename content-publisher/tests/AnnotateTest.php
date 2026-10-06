<?php
/**
 * Repair of forgotten lines, the retry loop with a scripted model, and the
 * Pipeline class end to end. Ported from repair.test.js and pipeline.test.js.
 */

use CPub\Publisher\Pipeline\Annotator;
use CPub\Publisher\Pipeline\FidelityException;
use CPub\Publisher\Pipeline\Llm\LlmException;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\MarkdownWriter;
use CPub\Publisher\Pipeline\Pipeline;
use CPub\Publisher\Pipeline\StructureException;
use CPub\Publisher\Pipeline\StructureMap;

class AnnotateTest extends PipelineTestCase {

	private array $ai;
	private array $ai_missing;
	private array $prepared;

	public function set_up() {
		parent::set_up();
		$this->prepared   = self::sample( '07-why-ai-is-good.txt' );
		$this->ai         = self::sample_map( '07-why-ai-is-good' );
		$this->ai_missing = self::sample_map( '07-missing-markers' );
	}

	private function placed_warnings( array $warnings ): array {
		return array_values( array_filter( $warnings, fn( $w ) => str_contains( $w, 'wasn’t placed' ) ) );
	}

	public function test_bare_image_markers_and_credit_lines_are_valid_parts_of_an_image() {
		$lines = $this->prepared['lines'];
		$r     = StructureMap::validate( $this->ai, $lines );
		$this->assertTrue( $r['ok'], implode( "\n", $r['errors'] ) );
		[ $doc, $post ] = $this->round_trip( $r['map'], $lines );
		$this->assertSame( array( 'headings' => 8, 'paragraphs' => 9, 'lists' => 1, 'tables' => 2, 'images' => 4 ), $post['counts'] );
		// The credit is published with the caption, word for word (licence attribution).
		$this->assertSame( 'Most modern AI is built on neural networks: layers of simple units that learn patterns from examples. Credit: Wikimedia Commons, CC BY-SA 3.0', $post['images'][0]['caption'] );
		$this->assertMatchesRegularExpression( '#<figcaption class="wp-element-caption">A protein structure predicted by AlphaFold\. [^<]*Credit: Wikimedia Commons</figcaption>#', $post['content'] );
		$this->assertDoesNotMatchRegularExpression( '/\[IMAGE\]|Image URL:|Tags:/', $doc['markdown'] );
	}

	public function test_feedback_names_the_missing_lines_and_their_text() {
		$r = StructureMap::validate( $this->ai_missing, $this->prepared['lines'] );
		$this->assertFalse( $r['ok'] );
		$this->assertMatchesRegularExpression( '/not assigned anywhere.*4, 25, 38, 51.*L4 "\[IMAGE\]"/s', implode( "\n", $r['errors'] ) );
	}

	public function test_repair_attaches_forgotten_image_markers_and_invents_no_paragraphs() {
		$r = StructureMap::repair_unassigned( $this->ai_missing, $this->prepared['lines'] );
		$this->assertTrue( $r['ok'], implode( "\n", $r['errors'] ?? array() ) );
		$firsts = array_map( fn( $b ) => $b['lines'][0], array_values( array_filter( $r['map']['blocks'], fn( $b ) => 'image' === $b['type'] ) ) );
		$this->assertSame( array( 4, 25, 38, 51 ), $firsts );
		$this->assertSame( array(), $this->placed_warnings( $r['warnings'] ) );
	}

	public function test_repair_keeps_a_forgotten_ordinary_line_as_its_own_paragraph() {
		$raw           = $this->ai;
		$raw['blocks'] = array_values( array_filter( $raw['blocks'], fn( $b ) => ! ( 'paragraph' === $b['type'] && 13 === $b['lines'][0] ) ) );
		$r             = StructureMap::repair_unassigned( $raw, $this->prepared['lines'] );
		$this->assertTrue( $r['ok'] );
		$this->assertNotEmpty( array_filter( $r['map']['blocks'], fn( $b ) => 'paragraph' === $b['type'] && 13 === $b['lines'][0] ) );
		$this->assertMatchesRegularExpression( '/Line 13 .*kept as its own paragraph/', $r['warnings'][0] );
	}

	public function test_repair_gives_up_on_garbage() {
		$lines = $this->prepared['lines'];
		$this->assertNull( StructureMap::repair_unassigned( 'nope', $lines ) );
		$this->assertNull( StructureMap::repair_unassigned( array( 'title' => 1 ), $lines ) );
	}

	public function test_annotate_a_model_that_keeps_making_the_mistake_still_yields_a_valid_post() {
		$fake = self::scripted( array( $this->ai_missing, $this->ai_missing ) );
		$r    = ( new Annotator( $fake ) )->annotate( $this->prepared );
		$this->assertTrue( $r['ok'] );
		$this->assertCount( 2, $r['attempts'] );
		$this->assertCount( 2, $fake->feedback );
	}

	public function test_annotate_a_model_that_fixes_it_on_retry_needs_no_repair() {
		$fake = self::scripted( array( $this->ai_missing, $this->ai ) );
		$r    = ( new Annotator( $fake ) )->annotate( $this->prepared );
		$this->assertCount( 2, $r['attempts'] );
		$this->assertSame( array(), $this->placed_warnings( $r['warnings'] ) );
		$this->assertStringContainsString( 'The structure map is invalid. Fix these problems', $fake->feedback[0] );
	}

	public function test_annotate_real_structural_nonsense_still_fails_loudly() {
		$fake = self::scripted( array( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'table', 'header' => true, 'lines' => array( 2, 3 ) ) ) ) ) );
		try {
			( new Annotator( $fake ) )->annotate( $this->prepared );
			$this->fail( 'expected StructureException' );
		} catch ( StructureException $e ) {
			$this->assertStringContainsString( 'No valid structure map', $e->getMessage() );
			$this->assertCount( 2, $e->attempts );
		}
	}

	public function test_annotate_retries_with_validation_errors_then_succeeds() {
		$broken                       = self::MAP_02;
		$broken['blocks'][0]['lines'] = array( 3, 4 ); // line 5 missing
		$fake                         = self::scripted( array( $broken, self::MAP_02 ) );
		$r                            = ( new Annotator( $fake ) )->annotate( self::sample( '02-hardwrapped.txt' ) );
		$this->assertCount( 2, $r['attempts'] );
		$this->assertCount( 1, $fake->feedback );
		$this->assertMatchesRegularExpression( '/not assigned anywhere.*5/', $fake->feedback[0] );
	}

	public function test_annotate_fails_after_max_attempts_on_unrepairable_order() {
		$broken           = self::MAP_02;
		$broken['blocks'] = array_reverse( $broken['blocks'] );
		$this->expectException( StructureException::class );
		$this->expectExceptionMessageMatches( '/No valid structure map after 2/' );
		( new Annotator( self::scripted( array( $broken ) ) ) )->annotate( self::sample( '02-hardwrapped.txt' ) );
	}

	public function test_annotate_keeps_forgotten_lines_as_paragraphs_with_warnings() {
		$forgot           = self::MAP_02;
		$forgot['blocks'] = array_slice( $forgot['blocks'], 1 ); // lines 3-5 missing
		$r                = ( new Annotator( self::scripted( array( $forgot ) ) ) )->annotate( self::sample( '02-hardwrapped.txt' ) );
		$this->assertCount( 3, $this->placed_warnings( $r['warnings'] ) );
	}

	public function test_annotate_stops_on_a_truncated_answer() {
		$this->expectException( StructureException::class );
		$this->expectExceptionMessageMatches( '/cut off/' );
		( new Annotator( self::scripted( array( array( 'input' => self::MAP_02, 'truncated' => true ) ) ) ) )->annotate( self::sample( '02-hardwrapped.txt' ) );
	}

	public function test_annotate_retries_after_an_unparseable_answer() {
		$fake = self::scripted( array( array( 'error' => 'Response was not valid JSON.' ), self::MAP_02 ) );
		$r    = ( new Annotator( $fake ) )->annotate( self::sample( '02-hardwrapped.txt' ) );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( 'not valid JSON', $fake->feedback[0] );
	}

	public function test_prompt_contains_numbered_lines() {
		$fake = self::scripted( array( self::MAP_02 ) );
		( new Annotator( $fake ) )->annotate( self::sample( '02-hardwrapped.txt' ) );
		[ $system, $user ] = $fake->prompts[0];
		$this->assertSame( Annotator::SYSTEM, $system );
		$this->assertStringStartsWith( "Post (18 numbered lines):\n\nL   1 | WHY OUR TEAM SWITCHED TO WEEKLY PLANNING\n", $user );
		$this->assertStringEndsWith( "\n\nReturn the structure map.", $user );
	}

	public function test_revise_sends_the_current_map_and_notes() {
		$revised                       = StructureMap::to_model( self::MAP_02 );
		$revised['blocks'][1]['level'] = 3;
		$fake                          = self::scripted( array( $revised ) );
		$r                             = ( new Annotator( $fake ) )->revise( self::sample( '02-hardwrapped.txt' ), self::MAP_02, 'make THE PROBLEM a subheading' );
		$this->assertSame( 3, $r['map']['blocks'][1]['level'] );
		[ $system, $user ] = $fake->prompts[0];
		$this->assertSame( Annotator::SYSTEM . Annotator::REVISE, $system );
		$this->assertStringContainsString( '"list_items"', $user );
		$this->assertStringContainsString( "Reviewer notes:\nmake THE PROBLEM a subheading", $user );
	}

	// ---- Pipeline ----

	public function test_pipeline_with_a_given_map_skips_the_ai() {
		$bytes = (string) file_get_contents( dirname( __DIR__ ) . '/samples/02-hardwrapped.txt' );
		$r     = ( new Pipeline() )->run( $bytes, '02-hardwrapped.txt', self::MAP_02 );
		$this->assertFalse( $r['ai'] );
		$this->assertSame( 'WHY OUR TEAM SWITCHED TO WEEKLY PLANNING', $r['title'] );
		$this->assertSame( 'Published 3 March 2026', $r['removed'][0]['text'] );
		$this->assertSame( array(), $r['post']['errors'] );
		$this->assertStringNotContainsString( '<!-- wp:', $r['post']['content'] );
	}

	public function test_pipeline_with_the_ai_sums_usage() {
		$broken                       = self::MAP_02;
		$broken['blocks'][0]['lines'] = array( 3, 4 );
		$bytes                        = (string) file_get_contents( dirname( __DIR__ ) . '/samples/02-hardwrapped.txt' );
		$r                            = ( new Pipeline( self::scripted( array( $broken, self::MAP_02 ) ) ) )->run( $bytes, 'x.txt' );
		$this->assertTrue( $r['ai'] );
		$this->assertSame( 2, $r['attempts'] );
		$this->assertSame( array( 'input_tokens' => 20, 'output_tokens' => 10 ), $r['usage'] );
	}

	public function test_pipeline_markdown_file_skips_the_ai() {
		$r = ( new Pipeline() )->run( "\xEF\xBB\xBF# Hi\r\n\r\nBody **bold**\r\n", 'post.MD' );
		$this->assertFalse( $r['ai'] );
		$this->assertSame( 'Hi', $r['title'] );
		$this->assertSame( "# Hi\n\nBody **bold**\n", $r['markdown'] );
		$this->assertStringContainsString( '<strong>bold</strong>', $r['post']['content'] );
	}

	public function test_pipeline_refuses_empty_huge_and_unconfigured() {
		foreach ( array( array( " \n", 'a.txt' ), array( str_repeat( 'a', Pipeline::MAX_BYTES + 1 ), 'a.txt' ) ) as [ $bytes, $name ] ) {
			try {
				( new Pipeline() )->run( $bytes, $name );
				$this->fail( 'expected refusal' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}
		$this->expectException( LlmException::class );
		$this->expectExceptionMessageMatches( '/No AI provider/' );
		( new Pipeline() )->run( "Title\nBody\n", 'a.txt' );
	}

	public function test_pipeline_rejects_an_invalid_given_map() {
		$this->expectException( StructureException::class );
		( new Pipeline() )->run( "Title\nBody\n", 'a.txt', array( 'title' => 1, 'excluded' => array(), 'blocks' => array() ) );
	}

	public function test_fidelity_gate_passes_identical_text_and_points_at_the_first_difference() {
		$lines = self::lines( 'T', 'Café costs far less than before, which is good' );
		$doc   = MarkdownWriter::from_map( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'paragraph', 'lines' => array( 2 ) ) ) ), $lines );
		Pipeline::check_fidelity( 'T', $doc['body_text'], MarkdownToPost::convert( $doc['markdown'] ) );

		// Simulate a writer bug: the Markdown no longer says what the source said.
		$post = MarkdownToPost::convert( str_replace( 'far less', 'much less', $doc['markdown'] ) );
		try {
			Pipeline::check_fidelity( 'T', $doc['body_text'], $post );
			$this->fail( 'expected FidelityException' );
		} catch ( FidelityException $e ) {
			$this->assertSame( 11, $e->at ); // characters, not bytes: "Café costs " is 11
			$this->assertStringContainsString( 'far less', $e->expected );
			$this->assertStringContainsString( 'much less', $e->got );
			$this->assertTrue( mb_check_encoding( $e->getMessage(), 'UTF-8' ) );
		}

		$this->expectException( FidelityException::class );
		Pipeline::check_fidelity( 'Other title', $doc['body_text'], MarkdownToPost::convert( $doc['markdown'] ) );
	}
}
