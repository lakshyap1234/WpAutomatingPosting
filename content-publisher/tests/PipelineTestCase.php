<?php
/**
 * Shared helpers for the pipeline tests (ported from the Node prototype's tests).
 */

use CPub\Publisher\Pipeline\Ingest;
use CPub\Publisher\Pipeline\Llm\Provider;
use CPub\Publisher\Pipeline\Llm\Session;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\MarkdownWriter;

abstract class PipelineTestCase extends WP_UnitTestCase {

	/** Hand-written correct map for samples/02-hardwrapped.txt. */
	public const MAP_02 = array(
		'title'    => 1,
		'excluded' => array( array( 'line' => 2, 'kind' => 'date' ) ),
		'blocks'   => array(
			array( 'type' => 'paragraph', 'lines' => array( 3, 4, 5 ) ),
			array( 'type' => 'heading', 'level' => 2, 'lines' => array( 6 ) ),
			array( 'type' => 'paragraph', 'lines' => array( 7, 8, 9 ) ),
			array( 'type' => 'heading', 'level' => 2, 'lines' => array( 10 ) ),
			array( 'type' => 'list', 'ordered' => true, 'items' => array( array( 11 ), array( 12 ), array( 13, 14 ) ) ),
			array( 'type' => 'paragraph', 'lines' => array( 15, 16, 17 ) ),
			array( 'type' => 'paragraph', 'lines' => array( 18 ) ),
		),
	);

	protected static function fixture( string $name ): string {
		return __DIR__ . '/fixtures/' . $name;
	}

	/** A shipped sample, ingested. */
	protected static function sample( string $file ): array {
		return Ingest::ingest( (string) file_get_contents( dirname( __DIR__ ) . '/samples/' . $file ) );
	}

	/** A recorded structure map: a shipped sample's, or a test-only one from fixtures/samples. */
	protected static function sample_map( string $name ): array {
		$shipped = dirname( __DIR__ ) . "/samples/{$name}.json";
		return json_decode( (string) file_get_contents( is_file( $shipped ) ? $shipped : self::fixture( "samples/{$name}.json" ) ), true );
	}

	/** Lines from plain strings: id from 1, text trimmed (like the prototype's L()). */
	protected static function lines( string ...$texts ): array {
		$out = array();
		foreach ( array_values( $texts ) as $i => $t ) {
			$out[] = array( 'id' => $i + 1, 'raw' => $t, 'text' => trim( $t ) );
		}
		return $out;
	}

	/** Whitespace squashed the way the prototype's tests did (/\s+/g → ' '). */
	protected static function ws( string $s ): string {
		return \CPub\Publisher\Pipeline\Text::squash( $s );
	}

	/** Writer → converter, asserting the fidelity property. */
	protected function round_trip( array $map, array $lines, string $format = 'blocks' ): array {
		$doc  = MarkdownWriter::from_map( $map, $lines );
		$post = MarkdownToPost::convert( $doc['markdown'], array( 'format' => $format ) );
		$this->assertSame( array(), $post['errors'], $doc['markdown'] );
		$this->assertSame( self::ws( $doc['body_text'] ), $post['plain_text'], $doc['markdown'] );
		return array( $doc, $post );
	}

	/**
	 * A provider that returns scripted answers (the last one repeats) and records feedback.
	 *
	 * @param array $answers each an array returned by Session::ask(), or a map (wrapped as input)
	 */
	protected static function scripted( array $answers ): object {
		return new class( $answers ) implements Provider {
			public array $feedback = array();
			public array $prompts  = array();
			public int $asks       = 0;

			public function __construct( private array $answers ) {
			}

			public function name(): string {
				return 'fake';
			}

			public function model(): string {
				return 'fake';
			}

			public function session( string $system, string $user_content ): Session {
				$this->prompts[] = array( $system, $user_content );
				$owner           = $this;
				return new class( $owner ) implements Session {
					public function __construct( private object $owner ) {
					}

					public function ask(): array {
						$o = $this->owner;
						$a = $o->answer( $o->asks++ );
						return isset( $a['input'] ) || isset( $a['error'] ) ? $a + array( 'truncated' => false, 'usage' => array() ) : array( 'input' => $a, 'truncated' => false, 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 5 ) );
					}

					public function reject( string $feedback ): void {
						$this->owner->feedback[] = $feedback;
					}
				};
			}

			public function answer( int $n ): array {
				return $this->answers[ min( $n, count( $this->answers ) - 1 ) ];
			}
		};
	}
}
