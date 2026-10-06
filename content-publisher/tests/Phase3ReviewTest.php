<?php
/**
 * Regression tests for the Phase 3 review findings.
 */

use CPub\Publisher\Admin\PipelinePage;
use CPub\Publisher\Installer;
use CPub\Publisher\Pipeline\Annotator;
use CPub\Publisher\Pipeline\Ingest;
use CPub\Publisher\Pipeline\Llm\Gemini;
use CPub\Publisher\Pipeline\Llm\Http;
use CPub\Publisher\Pipeline\Llm\LlmException;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\MarkdownWriter;
use CPub\Publisher\Pipeline\Pipeline;
use CPub\Publisher\Pipeline\StructureException;
use CPub\Publisher\Pipeline\StructureMap;
use CPub\Publisher\Pipeline\Text;
use CPub\Publisher\Settings\AiSettings;

class Phase3ReviewTest extends PipelineTestCase {

	public function tear_down() {
		Http::$backoff  = 8;
		Http::$deadline = null;
		$_POST          = $_REQUEST = array(); // phpcs:ignore
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	private static function run_map( string $src, array $blocks, int $title = 1 ): array {
		return ( new Pipeline() )->run( $src, 'x.txt', array( 'title' => $title, 'excluded' => array(), 'blocks' => $blocks ) );
	}

	private static function para( int ...$ids ): array {
		return array( 'type' => 'paragraph', 'lines' => $ids );
	}

	// 1. Keyed list_items from the model must not crash repair.
	public function test_keyed_list_items_are_an_error_not_a_crash() {
		$lines = Ingest::ingest( "Title\nA\nB" )['lines'];
		foreach ( array( 'list_items', 'items' ) as $key ) {
			$raw = array( 'title' => 1, 'blocks' => array( array( 'type' => 'list', 'ordered' => false, $key => array( 'a' => array( 2 ) ) ) ) );
			$this->assertFalse( StructureMap::validate( $raw, $lines )['ok'] );
			StructureMap::repair_unassigned( $raw, $lines ); // must not throw
			try {
				( new Annotator( self::scripted( array( $raw ) ) ) )->annotate( Ingest::ingest( "Title\nA\nB" ) );
			} catch ( StructureException $e ) {
				$this->assertStringContainsString( 'No valid structure map', $e->getMessage() );
			}
		}
	}

	// 2. A title with a double space or a tab passes the gate.
	public function test_title_with_inner_whitespace_passes() {
		foreach ( array( "My  Title\nBody", "My\tTitle\nBody" ) as $src ) {
			$r = self::run_map( $src, array( self::para( 2 ) ) );
			$this->assertSame( 'My Title', $r['post']['title'] );
		}
	}

	// 3. Image addresses that the parser percent-encodes still pass, and point at the same file.
	public function test_image_addresses_with_non_ascii_or_special_characters_pass() {
		foreach ( array( 'https://x.com/صورة.jpg', 'https://x.com/café.jpg', 'https://x.com/a{b}|c^d.jpg', 'https://x.com/a"b.jpg', 'https://x.com/a\\*b.jpg', 'https://x.com/a<b>.jpg', 'https://x.com/a%20b.jpg', 'https://x.com/?a=1&amp;b=2' ) as $url ) {
			$r   = self::run_map( "Title\n[IMAGE: a.jpg]\nImage URL: {$url}\nAlt text: A", array( array( 'type' => 'image', 'lines' => array( 2, 3, 4 ) ) ) );
			$src = $r['post']['images'][0]['src'];
			$this->assertSame( array(), $r['post']['errors'], $url );
			$this->assertSame( rawurldecode( $url ), rawurldecode( $src ), "{$url} → {$src}" );
			$this->assertMatchesRegularExpression( '/^[\x21-\x7E]+$/', $src, 'published address is plain ASCII' );
		}
	}

	// 4. Entity-looking text in a caption stays literal.
	public function test_caption_entities_stay_literal() {
		$r = self::run_map( "Title\nImage URL: https://x.com/a.jpg\nCaption: Tom &amp; Jerry &copy;", array( array( 'type' => 'image', 'lines' => array( 2, 3 ) ) ) );
		$this->assertSame( 'Tom &amp; Jerry &copy;', $r['post']['images'][0]['caption'] );
		$this->assertStringContainsString( 'Tom &amp;amp; Jerry &amp;copy;</figcaption>', $r['post']['content'] );
	}

	// 5. # in headings: only a closing run is escaped; addresses keep their #.
	public function test_hash_in_headings() {
		$r = self::run_map( "See https://x.com/a#b\nSee https://x.com/#_a_/b\nC# tips ##\n###\nBody", array( array( 'type' => 'heading', 'level' => 2, 'lines' => array( 2 ) ), array( 'type' => 'heading', 'level' => 2, 'lines' => array( 3 ) ), array( 'type' => 'heading', 'level' => 3, 'lines' => array( 4 ) ), self::para( 5 ) ) );
		$this->assertStringContainsString( '<a href="https://x.com/#_a_/b">https://x.com/#_a_/b</a>', $r['post']['content'] );
		$this->assertStringContainsString( '<h2>C# tips ##</h2>', $r['post']['content'] );
		$this->assertStringContainsString( '<h3>###</h3>', $r['post']['content'] );
		$this->assertSame( 'See https://x.com/a#b', $r['post']['title'] );
		$this->assertStringContainsString( '# See https://x.com/a#b', $r['markdown'] );

		$h = self::run_map( "T\nSee https://x.com/a#b", array( array( 'type' => 'heading', 'level' => 2, 'lines' => array( 2 ) ) ) );
		$this->assertStringContainsString( '<a href="https://x.com/a#b">https://x.com/a#b</a>', $h['post']['content'] );
	}

	// 6. Source list markers are removed only when every item has one, and letters are flagged.
	public function test_list_markers_are_not_silently_eaten() {
		$mixed = self::run_map( "T\nI. M. Pei built it\nAnother item", array( array( 'type' => 'list', 'ordered' => false, 'items' => array( array( 2 ), array( 3 ) ) ) ) );
		$this->assertStringContainsString( '<li>I. M. Pei built it</li>', $mixed['post']['content'] );
		$this->assertSame( array(), $mixed['warnings'] );

		$alone = self::run_map( "T\nI. M. Pei built it", array( array( 'type' => 'list', 'ordered' => true, 'items' => array( array( 2 ) ) ) ) );
		$this->assertStringContainsString( '<li>M. Pei built it</li>', $alone['post']['content'] );
		$this->assertMatchesRegularExpression( '/Line 2: “I\.” was treated as a list letter/', implode( "\n", $alone['warnings'] ) );

		$dashes = self::run_map( "T\n- one\n- two", array( array( 'type' => 'list', 'ordered' => false, 'items' => array( array( 2 ), array( 3 ) ) ) ) );
		$this->assertStringContainsString( '<li>one</li>', $dashes['post']['content'] );
		$this->assertSame( array(), $dashes['warnings'] );
	}

	// 7 + 9. Long lines stay fast; whitespace trimming is linear without PCRE JIT; PCRE failures are loud.
	public function test_long_lines_stay_fast() {
		$t = microtime( true );
		$r = self::run_map( "Title\n" . str_repeat( 'https://a.co/ ', 14000 ), array( self::para( 2 ) ) ); // ~196 KB
		$this->assertSame( array(), $r['post']['errors'] );
		$this->assertLessThan( 5, microtime( true ) - $t );

		// Without PCRE JIT (some hosts), trimming a long run of spaces must stay linear.
		// A fresh process, because patterns already compiled here keep their JIT code.
		$script = sprintf(
			'define("ABSPATH","/"); require %s; require %s; \\CPub\\Publisher\\Autoloader::register(); $t = microtime(true); $s = \\CPub\\Publisher\\Pipeline\\Text::trim("a" . str_repeat(" ", 60000) . "b \\t"); $n = \\CPub\\Publisher\\Pipeline\\Ingest::normalize("a" . str_repeat(" ", 60000) . "\\nb"); echo strlen($s), " ", json_encode($n), " ", round(microtime(true) - $t, 2);',
			var_export( dirname( __DIR__ ) . '/vendor-prefixed/autoload.php', true ),
			var_export( dirname( __DIR__ ) . '/src/Autoloader.php', true )
		);
		$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -r ' . escapeshellarg( $script ) );
		[ $len, $norm, $secs ] = explode( ' ', trim( $out ) );
		$this->assertSame( '60002', $len );
		$this->assertSame( '"a\\nb"', $norm );
		$this->assertLessThan( 1, (float) $secs, 'trim without JIT took ' . $secs . 's' );

		$this->expectException( \RuntimeException::class );
		Text::trim( "bad \xC3 utf-8 " );
	}

	public function test_size_limit() {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '200 KB' );
		( new Pipeline() )->run( str_repeat( 'a', Pipeline::MAX_BYTES + 1 ), 'a.txt' );
	}

	// 10. No <img> with a non-web address, anywhere.
	public function test_no_img_markup_for_non_web_addresses() {
		$r = self::run_map( "Title\nImage URL: javascript:alert(1)", array( array( 'type' => 'image', 'lines' => array( 2 ) ) ) );
		$this->assertNotEmpty( $r['post']['errors'] );
		$this->assertStringNotContainsString( '<img', $r['post']['content'] );
		foreach ( array( "# T\n\n## ![x](javascript:alert(1)) head\n", "# T\n\n- ![x](javascript:alert(1))\n", "# T\n\n![x](file.jpg)\n" ) as $md ) {
			$post = MarkdownToPost::convert( $md );
			$this->assertNotEmpty( $post['errors'], $md );
			$this->assertStringNotContainsString( '<img', $post['content'], $md );
		}
	}

	// 11. Uploaded-media attributes can't break out of the block comment or the class.
	public function test_block_attributes_and_size_slug_are_escaped() {
		$post = MarkdownToPost::convert( "# T\n\n![a](https://x.com/a.jpg)\n", array( 'media' => array( 'https://x.com/a.jpg' => array( 'id' => 7, 'src' => 'https://client/a.jpg', 'sizeSlug' => 'large" onload="x' ) ) ) );
		$this->assertStringContainsString( 'class="wp-block-image size-largeonloadx"', $post['content'] );
		$this->assertStringContainsString( '"sizeSlug":"largeonloadx"', $post['content'] );
		$post = MarkdownToPost::convert( "# T\n\n![a](https://x.com/a.jpg)\n", array( 'media' => array( 'https://x.com/a.jpg' => array( 'id' => 7, 'src' => 'https://client/a.jpg', 'sizeSlug' => '--><script>' ) ) ) );
		$this->assertStringNotContainsString( '<script', $post['content'] );
		$this->assertSame( 1, substr_count( $post['content'], '-->' ) - substr_count( $post['content'], '<!-- /wp:' ) );
	}

	// 12. Retries honour Retry-After, cover 529, and stop at the deadline.
	public function test_retry_after_529_and_deadline() {
		Http::$backoff = 0;
		$calls         = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return 1 === $calls
					? array( 'headers' => array( 'retry-after' => '0' ), 'body' => '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}', 'response' => array( 'code' => 529 ), 'cookies' => array(), 'filename' => null )
					: array( 'headers' => array(), 'body' => '{"status":"completed","outputs":[{"type":"text","text":"{}"}]}', 'response' => array( 'code' => 200 ), 'cookies' => array(), 'filename' => null );
			}
		);
		( new Gemini( 'k' ) )->session( 'S', 'P' )->ask();
		$this->assertSame( 2, $calls );

		Http::$deadline = microtime( true ) + 2;
		$calls          = 0;
		try {
			( new Gemini( 'k' ) )->session( 'S', 'P' )->ask();
			$this->fail( 'expected LlmException' );
		} catch ( LlmException $e ) {
			$this->assertStringContainsString( 'took too long', $e->getMessage() );
			$this->assertSame( 0, $calls );
		}

		// Near the deadline a retry isn't attempted: the 529 is reported instead.
		Http::$deadline = microtime( true ) + 20;
		Http::$backoff  = 10;
		remove_all_filters( 'pre_http_request' );
		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				++$calls;
				return array( 'headers' => array(), 'body' => '{"error":{"message":"Overloaded"}}', 'response' => array( 'code' => 529 ), 'cookies' => array(), 'filename' => null );
			}
		);
		$t = microtime( true );
		try {
			( new Gemini( 'k' ) )->session( 'S', 'P' )->ask();
			$this->fail( 'expected LlmException' );
		} catch ( LlmException $e ) {
			$this->assertStringContainsString( 'Overloaded', $e->getMessage() );
		}
		$this->assertLessThan( 2, microtime( true ) - $t );
		$this->assertSame( 1, $calls, 'no retry that would run past the deadline' );
	}

	public function test_annotator_sets_and_restores_the_deadline() {
		$fake = self::scripted( array( self::MAP_02 ) );
		$probe = new class( $fake ) implements \CPub\Publisher\Pipeline\Llm\Provider {
			public ?float $deadline = null;
			public function __construct( private object $inner ) {
			}
			public function name(): string {
				return 'probe';
			}
			public function model(): string {
				return 'probe';
			}
			public function session( string $system, string $user_content ): \CPub\Publisher\Pipeline\Llm\Session {
				$this->deadline = Http::$deadline;
				return $this->inner->session( $system, $user_content );
			}
		};
		( new Annotator( $probe ) )->annotate( self::sample( '02-hardwrapped.txt' ) );
		$this->assertEqualsWithDelta( microtime( true ) + Annotator::BUDGET, $probe->deadline, 5 );
		$this->assertNull( Http::$deadline );
	}

	// 13. Absurd IDs and non-string types are rejected.
	public function test_absurd_ids_and_types_are_rejected() {
		$lines = Ingest::ingest( "Title\nBody" )['lines'];
		foreach ( array( 1e20, 18446744073709555712.0, INF, -1, 0, 1.5, '1', true ) as $bad ) {
			$r = StructureMap::validate( array( 'title' => $bad, 'blocks' => array( self::para( 2 ) ) ), $lines );
			$this->assertFalse( $r['ok'], var_export( $bad, true ) );
			// Rejected as a bad ID, before any integer conversion could wrap it onto a real line.
			$this->assertSame( array( 'title: Expected a positive integer line ID' ), $r['errors'], var_export( $bad, true ) );
		}
		$this->assertFalse( StructureMap::validate( array( 'title' => 1, 'blocks' => array( array( 'type' => true, 'lines' => array( 2 ) ) ) ), $lines )['ok'] );
	}

	// The Pipeline test page shows any unexpected error instead of a fatal.
	public function test_pipeline_page_catches_unexpected_errors() {
		Installer::activate();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		AiSettings::save( 'gemini', '', 'AIzaSyTESTtestTESTtest1234' );
		add_filter(
			'pre_http_request',
			function () {
				throw new \Error( 'boom inside the HTTP layer' );
			}
		);
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $_REQUEST = array( 'cpub_run' => '1', 'cpub_sample' => '01-clean.txt', '_wpnonce' => wp_create_nonce( PipelinePage::NONCE ) ); // phpcs:ignore
		$log                       = ini_set( 'error_log', '/dev/null' );
		ob_start();
		( new PipelinePage() )->render();
		$html = (string) ob_get_clean();
		ini_set( 'error_log', (string) $log );
		$this->assertStringContainsString( 'Unexpected error (details are in the PHP error log): boom inside the HTTP layer', $html );
	}
}
