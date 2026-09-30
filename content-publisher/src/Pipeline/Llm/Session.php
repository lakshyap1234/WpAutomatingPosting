<?php
/**
 * One structuring conversation.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline\Llm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Session {

	/**
	 * @return array{input?:mixed, error?:string, truncated:bool, usage:array}
	 * @throws LlmException for problems a retry won't fix (bad key, unknown model, network)
	 */
	public function ask(): array;

	/** Tell the model what was wrong with its last answer. */
	public function reject( string $feedback ): void;
}
