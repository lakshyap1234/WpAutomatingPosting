<?php
/**
 * File -> reviewed-ready post: read, structure with the AI, write Markdown,
 * and check that the Markdown renders back to exactly the original text.
 *
 * A .md file skips the AI (it's already structured).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

use CPub\Publisher\Intake\Docx;
use CPub\Publisher\Pipeline\Llm\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Pipeline {

	// About 30,000 words: ten times a long blog post. The Markdown parser needs up to
	// ~700 bytes of memory per byte of symbol-dense text, so this also bounds memory.
	public const MAX_BYTES = 204800;

	public function __construct( private ?Provider $provider = null ) {
	}

	/**
	 * @return array{
	 *   name:string, ai:bool, markdown:string, title:string, body_text:string, removed:array,
	 *   warnings:string[], attempts:int, usage:array, map:?array, encoding:string,
	 *   post:array, image_names:array, docx:?array{images:array, links:array}
	 * }
	 * @throws \InvalidArgumentException|StructureException|FidelityException|Llm\LlmException
	 */
	public function run( string $bytes, string $name, ?array $map = null ): array {
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$docx = null;
		if ( 'docx' === $ext ) {
			// Word: read as text lines (plus its images), then exactly like a .txt.
			$docx  = Docx::read( $bytes );
			$bytes = $docx['text'];
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'The file is larger than 200 KB (about 30,000 words). Blog posts are much smaller; check it is the right file.' );
		}
		if ( '' === trim( $bytes ) ) {
			throw new \InvalidArgumentException( 'The file is empty.' );
		}

		if ( 'md' === $ext ) {
			$markdown = str_replace( array( "\r\n", "\r" ), "\n", Ingest::decode( $bytes )['text'] );
			$post     = MarkdownToPost::convert( $markdown, array( 'format' => 'html' ) );
			return array(
				'name'        => $name,
				'ai'          => false,
				'markdown'    => $markdown,
				'title'       => $post['title'],
				'body_text'   => $post['plain_text'],
				'removed'     => array(),
				'warnings'    => array(),
				'attempts'    => 0,
				'usage'       => array(),
				'map'         => null,
				'encoding'    => 'utf-8',
				'post'        => $post,
				'image_names' => array(),
				'docx'        => null,
			);
		}

		$prepared = Ingest::ingest( $bytes );
		$warnings = array();
		$attempts = array();
		if ( null !== $map ) {
			$result = StructureMap::validate( $map, $prepared['lines'] );
			if ( ! $result['ok'] ) {
				throw new StructureException( "The given structure map is invalid:\n- " . implode( "\n- ", $result['errors'] ) );
			}
		} else {
			if ( ! $this->provider ) {
				throw new Llm\LlmException( 'No AI provider is set up. Add an API key under Content Publisher > Settings.' );
			}
			$result   = ( new Annotator( $this->provider ) )->annotate( $prepared );
			$attempts = $result['attempts'];
		}
		$doc      = MarkdownWriter::from_map( $result['map'], $prepared['lines'] );
		$warnings = array_merge( $docx ? self::docx_warnings( $docx ) : array(), $result['warnings'], $doc['notes'] );
		$title    = $prepared['lines'][ $result['map']['title'] - 1 ]['text'];

		// Fidelity gate: the Markdown must render back to exactly the original text.
		$post = MarkdownToPost::convert( $doc['markdown'], array( 'format' => 'html' ) );
		self::check_fidelity( $title, $doc['body_text'], $post );

		$usage = array();
		foreach ( $attempts as $att ) {
			foreach ( (array) $att['usage'] as $k => $v ) {
				if ( is_int( $v ) ) {
					$usage[ $k ] = ( $usage[ $k ] ?? 0 ) + $v;
				}
			}
		}
		$names = array();
		foreach ( $doc['images'] as $img ) {
			if ( '' !== $img['filename'] ) {
				$names[ $img['src'] ] = $img['filename'];
			}
		}
		return array(
			'name'        => $name,
			'ai'          => null === $map,
			'markdown'    => $doc['markdown'],
			'title'       => $title,
			'body_text'   => $doc['body_text'],
			'removed'     => array_map( fn( $r ) => $r + array( 'markdown' => MarkdownWriter::line( $r['text'] ) ), $doc['removed'] ),
			'warnings'    => $warnings,
			'attempts'    => count( $attempts ),
			'usage'       => $usage,
			'map'         => $result['map'],
			'encoding'    => $docx ? 'docx' : $prepared['encoding'],
			'post'        => $post,
			'image_names' => $names,
			'docx'        => $docx ? array( 'images' => $docx['images'], 'links' => $docx['links'] ) : null,
		);
	}

	/**
	 * The converted post must say exactly what the source said: same title, and
	 * the same body text once runs of whitespace are squashed.
	 *
	 * @throws FidelityException pointing at the first difference
	 */
	public static function check_fidelity( string $title, string $body_text, array $post ): void {
		$expected = Text::squash( $body_text );
		$title    = Text::squash( $title ); // a heading's text is read with its spaces collapsed, like the body
		if ( $post['plain_text'] === $expected && $post['title'] === $title ) {
			return;
		}
		[ $a, $b ] = $post['title'] !== $title ? array( $title, $post['title'] ) : array( $expected, $post['plain_text'] );
		$ca        = mb_str_split( $a );
		$cb        = mb_str_split( $b );
		$i         = 0;
		$max       = min( count( $ca ), count( $cb ) );
		while ( $i < $max && $ca[ $i ] === $cb[ $i ] ) {
			++$i;
		}
		$from = max( 0, $i - 40 );
		throw new FidelityException( $i, implode( '', array_slice( $ca, $from, 80 ) ), implode( '', array_slice( $cb, $from, 80 ) ) );
	}

	/** What the reviewer needs to know about a Word file: what wasn't carried over. */
	private static function docx_warnings( array $docx ): array {
		$out = $docx['notes'];
		foreach ( $docx['links'] as $l ) {
			$out[] = "The Word file links “{$l['text']}” to {$l['url']}. Links aren’t carried over from Word; to keep it, write it as [{$l['text']}]({$l['url']})";
		}
		return $out;
	}
}
