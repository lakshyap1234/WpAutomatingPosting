<?php
/**
 * The port against the prototype: recorded outputs for every sample
 * (tests/fixtures/golden-samples.json, produced by tools/parity/node-side.mjs),
 * and the fidelity gate as a property over random Markdown-looking text.
 */

use CPub\Publisher\Pipeline\Ingest;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\MarkdownWriter;
use CPub\Publisher\Pipeline\StructureMap;

class ParityTest extends PipelineTestCase {

	public static function golden(): array {
		$cases = array();
		foreach ( json_decode( (string) file_get_contents( __DIR__ . '/fixtures/golden-samples.json' ), true ) as $case ) {
			$job                                                         = $case['job'];
			$cases[ $job['op'] . ' ' . ( $job['name'] ?? count( $cases ) ) ] = array( $job, $case['expected'] );
		}
		return $cases;
	}

	/**
	 * @dataProvider golden
	 */
	public function test_output_matches_the_prototype( array $job, $expected ) {
		switch ( $job['op'] ) {
			case 'validate':
				$v   = StructureMap::validate( $job['map'], Ingest::ingest( base64_decode( $job['base64'] ) )['lines'] );
				$got = array( 'ok' => $v['ok'], 'errors' => $v['errors'], 'warnings' => $v['warnings'], 'map' => $v['ok'] ? $v['map'] : null );
				break;
			case 'repair':
				$v   = StructureMap::repair_unassigned( $job['map'], Ingest::ingest( base64_decode( $job['base64'] ) )['lines'] );
				$got = array( 'ok' => $v['ok'], 'errors' => $v['errors'], 'warnings' => $v['warnings'], 'map' => $v['ok'] ? $v['map'] : null );
				break;
			case 'toMarkdown':
				$lines = Ingest::ingest( base64_decode( $job['base64'] ) )['lines'];
				// 'notes' is the port's addition (reviewer notes); compare what the prototype had.
				$got = array_intersect_key( MarkdownWriter::from_map( StructureMap::validate( $job['map'], $lines )['map'], $lines ), $expected );
				break;
			case 'post':
				$got = MarkdownToPost::convert( $job['markdown'], array( 'format' => $job['format'] ) );
				// 'file' (the image is an uploaded file) is the port's addition.
				$got['images'] = array_map( fn( $i ) => array_diff_key( $i, array( 'file' => 1 ) ), $got['images'] );
				break;
		}
		// Compare as JSON so PHP's list/object distinction for empty arrays doesn't matter.
		$this->assertSame( self::canon( $expected ), self::canon( $got ) );
	}

	private static function canon( $v ): string {
		$sort = function ( $x ) use ( &$sort ) {
			if ( ! is_array( $x ) ) {
				return $x;
			}
			$x = array_map( $sort, $x );
			if ( ! array_is_list( $x ) ) {
				ksort( $x );
			}
			return $x;
		};
		return (string) wp_json_encode( $sort( json_decode( (string) wp_json_encode( $v ), true ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** Same building blocks as tools/parity/atoms.py. */
	private const ATOMS = array(
		'*', '_', '`', '[', ']', '<', '>', '&', '#', '-', '+', '=', '~', '|', '\\', '(', ')', '.', ')', '!', '"', "'",
		'1.', '2)', '2026.', '&copy;', '&#39;', '&amp;', '&x', '**', '__', '~~~', '---', '===', '> ', '# ', '## ',
		'https://example.com/a_b*c', 'https://example.com/path?q=1&r=2', 'www.example.org', 'http://x.io).', '(see https://y.com/p)',
		'https://example.com/a(b)c', 'www.', 'https://', 'mailto:a@b.co', 'a@b.co', 'Node.js', 'example.com',
		'Café', 'naïve', '日本語', 'emoji 🎉', ' ', "\u{00A0}", '  ', "\t", '45°C', '10%', '“quoted”', '—', '•',
		'word', 'text', 'Hello', 'the', 'and', '[IMAGE: a.jpg]', 'Caption:', '<b>bold</b>', '<div>', '![x](y)', '[l](u)', '`code`',
	);

	/**
	 * Every random line, as a paragraph, heading, list item or table cell, goes
	 * through the real writer and converter and comes back as exactly the same text.
	 */
	public function test_fidelity_property_over_random_markdown_looking_text() {
		mt_srand( 20260929 );
		$failures = array();
		$checked  = 0;
		for ( $n = 0; $n < 1500; $n++ ) {
			$t = '';
			for ( $k = mt_rand( 1, 10 ); $k > 0; $k-- ) {
				$t .= self::ATOMS[ mt_rand( 0, count( self::ATOMS ) - 1 ) ] . ( mt_rand( 0, 1 ) ? ' ' : '' );
			}
			$t = trim( str_replace( "\t", ' ', $t ) );
			if ( '' === \CPub\Publisher\Pipeline\Text::trim( $t ) ) {
				continue;
			}
			$kind = array( 'paragraph', 'heading', 'list', 'cell' )[ $n % 4 ];
			if ( 'cell' === $kind ) {
				$src   = "Title\na\tb\n{$t}\tx\n";
				$block = array( 'type' => 'table', 'header' => true, 'lines' => array( 2, 3 ) );
			} else {
				$src   = "Title\n{$t}\n";
				$block = array(
					'paragraph' => array( 'type' => 'paragraph', 'lines' => array( 2 ) ),
					'heading'   => array( 'type' => 'heading', 'level' => 2, 'lines' => array( 2 ) ),
					'list'      => array( 'type' => 'list', 'ordered' => false, 'list_items' => array( array( 2 ) ) ),
				)[ $kind ];
			}
			$lines = Ingest::ingest( $src )['lines'];
			$v     = StructureMap::validate( array( 'title' => 1, 'excluded' => array(), 'blocks' => array( $block ) ), $lines );
			if ( ! $v['ok'] ) {
				continue; // e.g. a text that looks like an image line in a paragraph
			}
			++$checked;
			$doc  = MarkdownWriter::from_map( $v['map'], $lines );
			$post = MarkdownToPost::convert( $doc['markdown'], array( 'format' => 'html' ) );
			if ( $post['errors'] || 'Title' !== $post['title'] || self::ws( $doc['body_text'] ) !== $post['plain_text'] ) {
				$failures[] = "[{$kind}] " . wp_json_encode( $t, JSON_UNESCAPED_UNICODE ) . ' → ' . wp_json_encode( $post['plain_text'], JSON_UNESCAPED_UNICODE );
			}
		}
		$this->assertGreaterThan( 1200, $checked );
		$this->assertSame( array(), array_slice( $failures, 0, 5 ), count( $failures ) . ' failures' );
	}
}
