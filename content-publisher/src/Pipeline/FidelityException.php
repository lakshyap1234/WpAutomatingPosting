<?php
/**
 * The generated Markdown didn't render back to the original text. That is a
 * bug in the pipeline, never something to publish.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FidelityException extends \RuntimeException {

	public function __construct( public readonly int $at, public readonly string $expected, public readonly string $got ) {
		parent::__construct( "The generated Markdown didn't match the original text at character {$at}, so it wasn't used. This is a bug; please report it with the file.\n  expected: …{$expected}…\n  got:      …{$got}…" );
	}
}
