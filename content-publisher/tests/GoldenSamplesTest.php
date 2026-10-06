<?php
/**
 * Golden files: every samples/NN-name.txt, with its recorded structure map
 * samples/NN-name.json (what the AI answered), goes through the real pipeline.
 * The output must match tests/golden/:
 *
 *   NN-name.md           the Markdown the reviewer sees
 *   NN-name.blocks.html  the block content sent to the client site
 *   NN-name.json         title, counts, warnings and lines left out
 *
 * No AI is called: the map is given, as Pipeline::run allows.
 *
 * After an intended change to the output, regenerate and review the diff:
 *   UPDATE_GOLDEN=1 composer test:golden
 */

use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\Pipeline;

class GoldenSamplesTest extends WP_UnitTestCase {

	private const SAMPLES = __DIR__ . '/../samples';
	private const GOLDEN  = __DIR__ . '/golden';

	/** @return array<string, array{0: string}> sample name (no extension) */
	public static function samples(): array {
		$cases = array();
		foreach ( glob( self::SAMPLES . '/*.txt' ) as $txt ) {
			$name           = basename( $txt, '.txt' );
			$cases[ $name ] = array( $name );
		}
		return $cases;
	}

	private static function run_sample( string $name ): array {
		$map_file = self::SAMPLES . "/$name.json";
		self::assertFileExists( $map_file, "$name.txt has no recorded structure map ($name.json)." );
		$map    = json_decode( (string) file_get_contents( $map_file ), true );
		$result = ( new Pipeline() )->run( (string) file_get_contents( self::SAMPLES . "/$name.txt" ), "$name.txt", $map );
		$blocks = MarkdownToPost::convert( $result['markdown'], array( 'format' => 'blocks' ) );
		self::assertSame( array(), $blocks['errors'], "$name: the Markdown doesn't convert cleanly" );
		return array(
			'md'          => $result['markdown'],
			'blocks.html' => $blocks['content'] . "\n",
			'json'        => self::encode(
				array(
					'title'    => $blocks['title'],
					'encoding' => $result['encoding'],
					'counts'   => $blocks['counts'],
					'warnings' => $result['warnings'],
					'removed'  => array_map( fn( $r ) => array( 'line' => $r['line'], 'text' => $r['text'] ), $result['removed'] ),
				)
			),
		);
	}

	private static function encode( $value ): string {
		return wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
	}

	/** @dataProvider samples */
	public function test_sample_matches_its_golden_files( string $name ): void {
		$got = self::run_sample( $name );
		foreach ( $got as $ext => $content ) {
			$file = self::GOLDEN . "/$name.$ext";
			if ( getenv( 'UPDATE_GOLDEN' ) ) {
				wp_mkdir_p( self::GOLDEN );
				file_put_contents( $file, $content );
			}
			$this->assertFileExists( $file, "Missing golden file. Generate with UPDATE_GOLDEN=1, review it, commit it." );
			$this->assertSame( file_get_contents( $file ), $content, "$name.$ext differs from tests/golden. If the change is intended: UPDATE_GOLDEN=1, review the diff, commit." );
		}
	}

	public function test_every_golden_file_belongs_to_a_sample(): void {
		$names = array_keys( self::samples() );
		$this->assertNotEmpty( $names );
		foreach ( glob( self::GOLDEN . '/*' ) as $file ) {
			$this->assertMatchesRegularExpression( '/^(.+)\.(md|blocks\.html|json)$/', basename( $file ) );
			preg_match( '/^(.+?)\.(md|blocks\.html|json)$/', basename( $file ), $m );
			$this->assertContains( $m[1], $names, basename( $file ) . ' has no sample: delete it.' );
		}
	}

	/**
	 * The golden blocks were not just recorded from this code: they agree with
	 * the prototype's recorded output (tests/fixtures/golden-samples.json, from
	 * the original JavaScript), wherever the prototype covered that sample.
	 *
	 * @dataProvider samples
	 */
	public function test_golden_blocks_agree_with_the_prototype( string $name ): void {
		$prototype = null;
		foreach ( json_decode( (string) file_get_contents( __DIR__ . '/fixtures/golden-samples.json' ), true ) as $case ) {
			if ( 'post' === $case['job']['op'] && 'blocks' === $case['job']['format'] && "$name blocks" === ( $case['job']['name'] ?? '' ) ) {
				$prototype = $case;
			}
		}
		if ( ! $prototype ) {
			$this->markTestSkipped( "The prototype had no recorded output for $name." );
		}
		$this->assertSame( $prototype['job']['markdown'], file_get_contents( self::GOLDEN . "/$name.md" ), 'Markdown' );
		$this->assertSame( $prototype['expected']['content'] . "\n", file_get_contents( self::GOLDEN . "/$name.blocks.html" ), 'blocks' );
		$this->assertSame( $prototype['expected']['title'], json_decode( file_get_contents( self::GOLDEN . "/$name.json" ), true )['title'] );
	}
}
