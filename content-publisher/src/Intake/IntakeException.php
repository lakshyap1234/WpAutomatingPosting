<?php
/**
 * A file that can't be taken in, with a message for the person who uploaded it.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Intake;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class IntakeException extends \InvalidArgumentException {
}
