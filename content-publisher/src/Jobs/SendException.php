<?php
/**
 * Sending failed. `transient` means trying again later may work (the site was
 * down or busy); otherwise someone has to fix something first.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SendException extends \RuntimeException {

	public function __construct( string $message, public readonly bool $transient = false ) {
		parent::__construct( $message );
	}
}
