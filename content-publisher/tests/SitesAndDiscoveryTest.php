<?php

use CPub\Publisher\Connections\Discovery;
use CPub\Publisher\Connections\Sites;

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

	public function test_discovers_endpoints(): void {
		$meta = Discovery::discover( $this->client->url );
		$this->assertIsArray( $meta );
		$this->assertSame( 'Client Test', $meta['site_name'] );
		$this->assertStringEndsWith( '/oauth/token', $meta['token_endpoint'] );
	}

	/** @dataProvider bad_metadata */
	public function test_refuses_endpoints_off_the_site( string $key, string $value ): void {
		$this->client->metadata_override = array( $key => $value );
		$meta = Discovery::discover( $this->client->url );
		$this->assertWPError( $meta );
		$this->assertSame( 'cpub_bad_metadata', $meta->get_error_code() );
	}

	public static function bad_metadata(): array {
		return array(
			'token elsewhere'   => array( 'token_endpoint', 'https://evil.test/token' ),
			'token over http'   => array( 'token_endpoint', 'http://client.test/wp-json/cpub-connector/v1/oauth/token' ),
			'approval elsewhere'=> array( 'authorization_endpoint', 'https://evil.test/approve' ),
		);
	}

	public function test_no_pkce_s256_is_refused(): void {
		$this->client->metadata_override = array( 'code_challenge_methods_supported' => array( 'plain' ) );
		$this->assertSame( 'cpub_bad_metadata', Discovery::discover( $this->client->url )->get_error_code() );
	}

	public function test_unreachable_site(): void {
		$this->client->offline = true;
		$err = Discovery::discover( $this->client->url );
		$this->assertSame( 'cpub_unreachable', $err->get_error_code() );
		$this->assertStringContainsString( 'timed out', $err->get_error_message() );
	}

	public function test_site_without_connector(): void {
		$err = Discovery::discover( 'https://no-connector.test' ); // not handled by the fake: real HTTP is blocked in tests
		$this->assertWPError( $err );
	}
}
