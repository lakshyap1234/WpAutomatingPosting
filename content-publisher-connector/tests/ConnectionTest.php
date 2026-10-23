<?php

use CPub\Connector\OAuth\Scopes;

/**
 * GET /cpub-connector/v1/connection: the agency gates its buttons per site on
 * this response (API.md, "The connection"). A change here is a change to that
 * contract: update API.md with it.
 */
class ConnectionTest extends OAuthTestCase {

	private function connection( array $fields = array(), ?string $scope = null ): array {
		$token = $this->connect( $fields, null === $scope ? array() : array( 'scope' => $scope ) )['access_token'];
		$res   = $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return $res->get_data();
	}

	public function test_the_documented_shape(): void {
		$data = $this->connection();
		$this->assertSame(
			array( 'site_name', 'site_url', 'connector_version', 'scopes', 'connected_since', 'user', 'can_publish', 'features', 'scopes_supported' ),
			array_keys( $data )
		);
		$this->assertSame( array( 'id', 'name', 'role', 'separate_account' ), array_keys( $data['user'] ) );
		$this->assertSame( array( 'pages', 'preview', 'proposals', 'jsonld' ), array_keys( $data['features'] ) );
		$this->assertContainsOnly( 'bool', $data['features'] );

		$this->assertSame( CPUB_CONNECTOR_VERSION, $data['connector_version'] );
		$this->assertSame( array( 'posts:write', 'media:write', 'terms:read', 'account:read' ), $data['scopes'] );
		$this->assertSame( Scopes::all(), $data['scopes_supported'] );
		$this->assertFalse( $data['can_publish'] );
		$this->assertTrue( $data['user']['separate_account'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d/', $data['connected_since'] );
	}

	public function test_the_default_scopes(): void {
		$this->assertSame(
			array( 'pages' => false, 'preview' => true, 'proposals' => false, 'jsonld' => true ),
			$this->connection()['features']
		);
	}

	public function test_everything_granted(): void {
		$data = $this->connection( array( 'can_publish' => '1' ), self::SCOPES . ' pages:write proposals:write' );
		$this->assertSame( array( 'pages' => true, 'preview' => true, 'proposals' => true, 'jsonld' => true ), $data['features'] );
		$this->assertTrue( $data['can_publish'] );
	}

	public function test_pages_need_someone_who_can_write_pages(): void {
		$scope  = self::SCOPES . ' pages:write';
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertTrue( $this->connection( array( 'post_as' => 'agency' ), $scope )['features']['pages'], 'the separate account' );
		$this->assertTrue( $this->connection( array( 'post_as' => (string) $editor ), $scope )['features']['pages'], 'an Editor' );

		$as_author = $this->connection( array( 'post_as' => (string) $author ), $scope );
		$this->assertContains( 'pages:write', $as_author['scopes'], 'granted...' );
		$this->assertFalse( $as_author['features']['pages'], '...but WordPress doesn\'t let Authors write pages' );
		$this->assertTrue( $as_author['features']['preview'], 'posts still preview' );
	}

	public function test_only_suggestions(): void {
		$this->assertSame(
			array( 'pages' => false, 'preview' => false, 'proposals' => true, 'jsonld' => true ),
			$this->connection( array(), 'account:read proposals:write' )['features']
		);
	}

	public function test_nothing_but_the_connection_check(): void {
		$data = $this->connection( array(), 'account:read' );
		$this->assertSame( array( 'pages' => false, 'preview' => false, 'proposals' => false, 'jsonld' => false ), $data['features'] );
		$this->assertSame( array( 'account:read' ), $data['scopes'] );
	}
}
