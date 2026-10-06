<?php
/**
 * PHPUnit bootstrap: WordPress test library + this plugin, loaded as a must-use plugin.
 * Set up the library first with dev/install-wp-tests.sh.
 */

$cpub_repo = dirname( __DIR__, 2 );

// A fixed test key, as wp-config.php would define it on a real site.
define( 'CPUB_PUBLISHER_KEY', base64_encode( str_repeat( "\x42", 32 ) ) );
require $cpub_repo . '/tests/wp-bootstrap-common.php';

cpub_tests_boot(
	static function () {
		require dirname( __DIR__ ) . '/content-publisher.php';
	}
);

require __DIR__ . '/FakeConnector.php';
require __DIR__ . '/ConnectionsTestCase.php';
require __DIR__ . '/PipelineTestCase.php';
require __DIR__ . '/JobsTestCase.php';
require __DIR__ . '/SenderTestCase.php';
