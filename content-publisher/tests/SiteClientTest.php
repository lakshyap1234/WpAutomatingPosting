<?php

use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\SiteClient;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\Wpcom;

/** Calls to a connected site, through WordPress.com. */
class SiteClientTest extends ConnectionsTestCase {

	public function test_routes_map_to_wordpress_com(): void {
		$this->assertSame( 'https://public-api.wordpress.com/wp/v2/sites/42/posts', Wpcom::url( 42, '/wp/v2/posts' ) );
		$this->assertSame( 'https://public-api.wordpress.com/wp/v2/sites/42/posts/7?context=edit', Wpcom::url( 42, '/wp/v2/posts/7', array( 'context' => 'edit' ) ) );
		$this->assertSame( 'https://public-api.wordpress.com/rest/v1.1/sites/42/media/new', Wpcom::url( 42, '/rest/v1.1/media/new' ) );
		$this->assertSame( 'https://public-api.wordpress.com/rest/v1.1/sites/42', Wpcom::url( 42, '/rest/v1.1' ) );
		$this->assertSame( 'https://public-api.wordpress.com/rest/v1.1/me', Wpcom::url( 42, '/rest/v1.1/me' ), 'not site-specific' );
		$this->expectException( InvalidArgumentException::class );
		Wpcom::url( 42, '/cpub-connector/v1/connection' );
	}

	public function test_sends_the_token_for_this_site(): void {
		$site = $this->connect();
		$res  = SiteClient::request( $site, 'GET', '/wp/v2/users/me' );
		$this->assertSame( 200, $res['status'] );
		$this->assertSame( '/wp/v2/users/me', $this->client->last['route'] );
		$this->assertSame( 'Bearer ' . Credentials::load( (int) $site->id )['access'], $this->client->last['headers']['authorization'] );
	}

	public function test_a_revoked_token_disconnects_the_site(): void {
		$site = $this->connect();
		$this->client->revoke(); // removed on WordPress.com, or Jetpack disconnected
		$res = SiteClient::request( $site, 'GET', '/wp/v2/posts' );
		$this->assertSame( 'cpub_disconnected', $res->get_error_code() );
		$site = Sites::get( (int) $site->id );
		$this->assertSame( Sites::DISCONNECTED, $site->status );
		$this->assertStringContainsString( 'no longer accepts our access', $site->last_error );
		$this->assertNull( Credentials::load( (int) $site->id ), 'the dead token is forgotten' );
	}

	public function test_other_refusals_keep_the_connection(): void {
		$site                                         = $this->connect();
		$this->client->fail_next['GET /wp/v2/posts']  = 401; // not "invalid_token": e.g. a plugin on the site
		$this->client->fail_next['GET /wp/v2/media']  = 403;
		$this->assertSame( 401, SiteClient::request( $site, 'GET', '/wp/v2/posts' )['status'] );
		$this->assertSame( 403, SiteClient::request( $site, 'GET', '/wp/v2/media' )['status'] );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status );
	}

	public function test_network_failure_keeps_the_connection(): void {
		$site                  = $this->connect();
		$this->client->offline = true;
		$this->assertWPError( SiteClient::request( $site, 'GET', '/wp/v2/posts' ) );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
	}

	public function test_json_body(): void {
		$site = $this->connect();
		SiteClient::request( $site, 'POST', '/wp/v2/posts', array( 'json' => array( 'title' => 'Hi' ) ) );
		$this->assertSame( 'Hi', $this->client->last['body']['title'] );
		$this->assertSame( 'application/json', $this->client->last['headers']['content-type'] );
	}

	public function test_multipart_body(): void {
		$site = $this->connect();
		$res  = SiteClient::request(
			$site,
			'POST',
			'/rest/v1.1/media/new',
			array(
				'multipart' => array(
					array( 'name' => 'media[]', 'filename' => 'a"b.png', 'type' => 'image/png', 'value' => "\x89PNG bytes" ),
					array( 'name' => 'attrs[0][alt]', 'value' => 'An alt' ),
				),
			)
		);
		$this->assertSame( 200, $res['status'] );
		$m = reset( $this->client->media );
		$this->assertSame( 'ab.png', $m['filename'], 'quotes can\'t break the header' );
		$this->assertSame( 'image/png', $m['type'] );
		$this->assertSame( strlen( "\x89PNG bytes" ), $m['bytes'] );
		$this->assertSame( 'An alt', $m['alt_text'] );
	}

	public function test_refuses_when_not_connected(): void {
		$site = $this->connect();
		Sites::update( (int) $site->id, array( 'status' => Sites::DISCONNECTED ) );
		$this->assertSame( 'cpub_not_connected', SiteClient::request( Sites::get( (int) $site->id ), 'GET', '/wp/v2/posts' )->get_error_code() );
		Sites::update( (int) $site->id, array( 'status' => Sites::CONNECTED, 'wpcom_site_id' => 0 ) );
		$this->assertSame( 'cpub_not_connected', SiteClient::request( Sites::get( (int) $site->id ), 'GET', '/wp/v2/posts' )->get_error_code(), 'a site from before Jetpack' );
	}
}
