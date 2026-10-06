<?php
/**
 * PHPUnit bootstrap: WordPress test library + this plugin, loaded as a must-use plugin.
 * See TESTING.md at the repo root.
 */

require dirname( __DIR__, 2 ) . '/tests/wp-bootstrap-common.php';

cpub_tests_boot(
	static function () {
		require dirname( __DIR__ ) . '/content-publisher-connector.php';
	}
);

require __DIR__ . '/OAuthTestCase.php';
