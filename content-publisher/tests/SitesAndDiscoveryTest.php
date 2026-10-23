<?php

use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\Wpcom;

class SitesAndDiscoveryTest extends ConnectionsTestCase {

	/** @dataProvider urls */
	public function test_normalize_url( string $in, ?string $out ): void {
		$this->assertSame( $out, Sites::normalize_url( $in ) );
	}

	public static function urls(): array {
		return array(
			'bare host'          => array( 'Client.Example.com', 'https://client.example.com' ),
			'trailing slash'     => array( 'https://client.example.com/', 'https://client.example.com' ),
			'subdirectory'       => array( 'https://example.com/blog/', 'https://example.com/blog' ),
			'query dropped'      => array( 'https://example.com/?x=1#y', 'https://example.com' ),
			'port kept'          => array( 'https://example.com:8443', 'https://example.com:8443' ),
			'default port gone'  => array( 'https://example.com:443/', 'https://example.com' ),
			'http refused'       => array( 'http://example.com', null ),
			'credentials refused'=> array( 'https://user:pw@example.com', null ),
			'no dot refused'     => array( 'https://localhost', null ),
			'junk refused'       => array( 'javascript:alert(1)', null ),
		);
	}

	public function test_looks_the_site_up_on_wordpress_com(): void {
		$found = Wpcom::lookup( $this->client->url );
		$this->assertIsArray( $found );
		$this->assertSame( $this->client->site_id, $found['id'] );
		$this->assertSame( 'Client Test', $found['name'] );
		$this->assertTrue( $found['jetpack'] );
		$this->assertSame( array( 'GET /rest/v1.1/sites/client.test' ), $this->client->calls, 'asked without a token' );
	}

	public function test_a_site_in_a_folder_is_looked_up_as_host_and_path(): void {
		$this->client->url = 'https://client.test/blog';
		$this->assertIsArray( Wpcom::lookup( 'https://client.test/blog' ) );
		$this->assertSame( array( 'GET /rest/v1.1/sites/client.test::blog' ), $this->client->calls );
	}

	public function test_a_site_without_jetpack(): void {
		$this->client->known = false;
		$err                 = Wpcom::lookup( $this->client->url );
		$this->assertSame( 'cpub_no_jetpack', $err->get_error_code() );
		$this->assertStringContainsString( 'needs Jetpack', $err->get_error_message() );
	}

	public function test_wordpress_com_unreachable(): void {
		$this->client->offline = true;
		$err                   = Wpcom::lookup( $this->client->url );
		$this->assertSame( 'cpub_unreachable', $err->get_error_code() );
		$this->assertStringContainsString( 'timed out', $err->get_error_message() );
	}

	/** @dataProvider roles */
	public function test_role_from_capabilities( array $caps, string $role ): void {
		$this->assertSame( $role, Wpcom::role( $caps ) );
	}

	public static function roles(): array {
		return array(
			'administrator' => array( array( 'manage_options' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'edit_posts' => true ), 'administrator' ),
			'editor'        => array( array( 'edit_others_posts' => true, 'publish_posts' => true, 'edit_posts' => true ), 'editor' ),
			'author'        => array( array( 'publish_posts' => true, 'edit_posts' => true ), 'author' ),
			'contributor'   => array( array( 'edit_posts' => true ), 'contributor' ),
			'subscriber'    => array( array( 'read' => true ), '' ),
		);
	}
}
