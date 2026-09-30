<?php
/**
 * An AI provider that can return a structure map.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline\Llm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Provider {

	public function name(): string;

	public function model(): string;

	/** A conversation for one post: ask(), then reject($feedback) and ask() again on a bad answer. */
	public function session( string $system, string $user_content ): Session;
}
