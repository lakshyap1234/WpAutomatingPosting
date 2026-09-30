<?php
/**
 * A problem talking to the AI provider. `transient` means it may well work
 * later (rate limit, overload, network), so a background job retries it.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline\Llm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LlmException extends \RuntimeException {

	public function __construct( string $message, public readonly bool $transient = false ) {
		parent::__construct( $message );
	}
}
