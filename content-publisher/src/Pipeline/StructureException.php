<?php
/**
 * The AI couldn't produce a usable structure (after retries and repair).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class StructureException extends \RuntimeException {

	public function __construct( string $message, public readonly array $attempts = array() ) {
		parent::__construct( $message );
	}
}
