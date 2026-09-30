<?php
/**
 * The only AI step. The model receives numbered lines and returns a structure
 * map (line IDs only); it never outputs post text. Invalid answers are sent
 * back with the reasons, and as a last resort lines the model left out are
 * kept as their own paragraphs (with a warning) rather than failing the post.
 *
 * Port of the prototype's src/annotate.js; the prompts are identical.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

use CPub\Publisher\Pipeline\Llm\Http;
use CPub\Publisher\Pipeline\Llm\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Annotator {

	public const MAX_ATTEMPTS = 2;

	public const SYSTEM = <<<'PROMPT'
You structure plain-text blog posts for publishing. The text is final and must be published exactly as written. Your only job is to decide the STRUCTURE, by line ID.

You will see the post as numbered lines ("L  12 | text"). "(blank)" marks an empty line; blank lines usually separate blocks but are not guaranteed.

Rules:
- Reference lines ONLY by ID. Never write, fix, or paraphrase any text.
- Every line ID must be used exactly once: as the title, as an excluded line, or inside exactly one block.
- title: the line that is the post's title (usually the first line). Pick it; never invent one.
- excluded: ONLY author bylines ("By Jane Doe") and publication dates near the top or bottom. When unsure, it is body content, not excluded.
- Blocks must be in reading order; each block's lines must be consecutive IDs.
- paragraph: one or more consecutive lines. Text is often hard-wrapped, so consecutive lines that continue a sentence belong to the same paragraph. Set breaks=true only when line breaks are meaningful (addresses, verse, sign-offs).
- heading: exactly one short line that introduces the section after it (often no ending punctuation, may be Title Case or ALL CAPS, or end with a colon). Use level 2 for main sections, 3 for sub-sections, 4 rarely. Never use level 1; the theme renders the title as H1.
- list: consecutive lines that are items (bullets like "-", "*", "•", numbers like "1." or "1)", or clearly parallel short lines after an introducing sentence). Put them in list_items: one array of line IDs per item, e.g. [[11],[12],[13,14]] where item 3 wraps over lines 13-14. ordered=true for numbered/lettered items.
- A line that introduces a list ("Here's what you need:") is a paragraph, not a heading, unless it is clearly a section title.
- table: consecutive lines that form a table. Two kinds are supported: every line has cells separated by tabs (shown as ⇥), or every line starts with | and separates cells with |. Include a |---|---| separator row in the table's lines. header=true when the first row holds column titles. Lines whose columns are only lined up with spaces are NOT a table: make them paragraphs.
- image: the lines describing one image: a marker line "[IMAGE]" or "[IMAGE: file.jpg]", then usually "Image URL: …", "Alt text: …", "Caption: …" and sometimes "Credit: …" or "Source: …". The marker line and all of the image's lines go in ONE image block, e.g. lines [4,5,6,7,8]; each image is its own block. Never put these lines in a paragraph, and never leave the [IMAGE] marker out.
- excluded: besides bylines and dates, lines of post metadata such as "Tags: …", "Category: …" or "Categories: …" are excluded with kind "metadata". Never exclude ordinary sentences.
PROMPT;

	public const REVISE = <<<'PROMPT'


You are now REVISING an existing structure map based on reviewer notes. Return the COMPLETE updated map (not a diff). Apply only structural changes: title choice, excluded lines, block types, grouping, heading levels, list ordering, line breaks. You cannot change wording, spelling, or content. If a note asks for any text change, leave the text alone and list that note in notes_not_applied.
PROMPT;

	public function __construct( private Provider $provider, private int $max_attempts = self::MAX_ATTEMPTS ) {
	}

	/**
	 * @param array{lines:array, layout:array} $prepared
	 * @return array{ok:true, map:array, warnings:string[], attempts:array}
	 * @throws StructureException when no valid map could be obtained
	 */
	public function annotate( array $prepared ): array {
		$count = count( $prepared['lines'] );
		return $this->call(
			self::SYSTEM,
			"Post ({$count} numbered lines):\n\n" . Ingest::render_for_prompt( $prepared['layout'] ) . "\n\nReturn the structure map.",
			$prepared['lines']
		);
	}

	/** Structural revision from reviewer notes; wording can't change. */
	public function revise( array $prepared, array $current_map, string $notes ): array {
		$count = count( $prepared['lines'] );
		return $this->call(
			self::SYSTEM . self::REVISE,
			"Post ({$count} numbered lines):\n\n" . Ingest::render_for_prompt( $prepared['layout'] ) . "\n\n" .
				"Current structure map:\n" . json_encode( StructureMap::to_model( $current_map ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\n" .
				"Reviewer notes:\n{$notes}\n\nReturn the complete revised structure map.",
			$prepared['lines']
		);
	}

	/** Seconds one structuring run may take, retries included (a page request waits for it). */
	public const BUDGET = 240;

	private function call( string $system, string $user, array $lines ): array {
		$outer            = Http::$deadline;
		Http::$deadline ??= microtime( true ) + self::BUDGET;
		try {
			return $this->attempts( $system, $user, $lines );
		} finally {
			Http::$deadline = $outer;
		}
	}

	private function attempts( string $system, string $user, array $lines ): array {
		$session  = $this->provider->session( $system, $user );
		$attempts = array();
		for ( $attempt = 1; $attempt <= $this->max_attempts; $attempt++ ) {
			$out = $session->ask();
			if ( ! empty( $out['truncated'] ) ) {
				throw new StructureException( 'The AI\'s answer was cut off. The post may be too long for one request.', $attempts );
			}
			$result     = array_key_exists( 'input', $out ) ? StructureMap::validate( $out['input'], $lines ) : array( 'ok' => false, 'errors' => array( $out['error'] ?? 'No answer.' ), 'warnings' => array() );
			$attempts[] = array( 'attempt' => $attempt, 'ok' => $result['ok'], 'errors' => $result['errors'], 'usage' => $out['usage'] ?? array(), 'raw' => $out['input'] ?? ( $out['error'] ?? null ) );
			if ( $result['ok'] ) {
				return $result + array( 'attempts' => $attempts );
			}
			// Send the validation errors back so the model can fix its map.
			$session->reject( "The structure map is invalid. Fix these problems and return the complete corrected map:\n- " . implode( "\n- ", $result['errors'] ) );
		}
		$last     = end( $attempts );
		$repaired = StructureMap::repair_unassigned( $last['raw'], $lines );
		if ( $repaired && $repaired['ok'] ) {
			return $repaired + array( 'attempts' => $attempts );
		}
		throw new StructureException( "No valid structure map after {$this->max_attempts} attempt(s):\n- " . implode( "\n- ", $last['errors'] ), $attempts );
	}
}
