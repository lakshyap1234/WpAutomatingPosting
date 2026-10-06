<?php

use CPub\Connector\Auth\BearerAuth;

/**
 * The token must not sign anyone in outside a real REST request, whatever the
 * URL looks like (e.g. admin-ajax.php?rest_route=...). Runs in its own process
 * so REST_REQUEST is not defined.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class OutsideRestTest extends OAuthTestCase {

	/** @dataProvider places */
	public function test_token_is_ignored_outside_rest( string $uri, array $get ): void {
		$token = $this->connect()['access_token'];
		$this->assertFalse( defined( 'REST_REQUEST' ), 'This test needs REST_REQUEST undefined.' );

		$_SERVER['REQUEST_URI']        = $uri;
		$_GET                          = $get;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		BearerAuth::reset();
		$GLOBALS['current_user'] = null;

		$this->assertSame( 0, get_current_user_id(), "Signed in at $uri" );
	}

	public static function places(): array {
		return array(
			'admin-ajax with rest_route' => array( '/wp-admin/admin-ajax.php?rest_route=/wp/v2/posts', array( 'rest_route' => '/wp/v2/posts' ) ),
			'admin-post with rest_route' => array( '/wp-admin/admin-post.php?rest_route=/x', array( 'rest_route' => '/x' ) ),
			'path containing wp-json'    => array( '/wp-admin/admin-ajax.php/wp-json/wp/v2/posts', array() ),
			'front end'                  => array( '/', array() ),
		);
	}
}
