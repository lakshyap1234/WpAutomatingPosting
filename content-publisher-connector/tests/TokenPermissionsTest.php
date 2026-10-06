<?php

use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\OAuth\Grants;

/**
 * What a token may do on a client's site, one test per rule.
 *
 * This is the checklist for Auth/RouteGate.php, Auth/Sandbox.php, Rest/Ownership.php
 * and the OAuth grants. Some rules are also tested in more detail elsewhere
 * (ClientUserTest, MediaTest, TokenTest, OutsideRestTest); these tests state each
 * rule once, from the agency's side, and check that nothing changed on the site.
 */
class TokenPermissionsTest extends OAuthTestCase {

	private int $editor;

	public function set_up(): void {
		parent::set_up();
		// An Editor: in WordPress they may edit others' posts and publish, so every
		// refusal below comes from the Connector, not from the role.
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	private function token( bool $can_publish = false ): string {
		$fields = array( 'post_as' => (string) $this->editor ) + ( $can_publish ? array( 'can_publish' => '1' ) : array() );
		return $this->connect( $fields )['access_token'];
	}

	private function draft_of_ours( string $token ): int {
		$res = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'Ours', 'status' => 'draft' ), $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return (int) $res->get_data()['id'];
	}

	private static function post_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'" );
	}

	private static function revoked_reason(): ?string {
		global $wpdb;
		return $wpdb->get_var( 'SELECT revoked_reason FROM ' . \CPub\Connector\Installer::table( 'grants' ) . ' ORDER BY id DESC LIMIT 1' );
	}

	// ------------------------------------------------------------ posts the agency didn't create

	public function test_editing_a_post_the_agency_did_not_create_is_403(): void {
		$token = $this->token( true );
		foreach ( array( 'draft', 'pending', 'publish' ) as $status ) {
			$theirs = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => $status, 'post_title' => "Site's $status" ) );
			$res    = $this->api( 'POST', "/wp/v2/posts/{$theirs}", array( 'title' => 'Changed by the agency' ), $token );
			$this->assertSame( 403, $res->get_status(), $status );
			$this->assertSame( "Site's $status", get_post( $theirs )->post_title, $status );
			$this->assertSame( 403, $this->api( 'DELETE', "/wp/v2/posts/{$theirs}", array(), $token )->get_status(), "delete $status" );
			$this->assertSame( $status, get_post_status( $theirs ), "delete $status" );
		}
	}

	// ------------------------------------------------------------ fields outside WRITABLE_FIELDS

	/** @dataProvider forbidden_fields */
	public function test_a_field_outside_writable_fields_is_403_on_create( string $field, $value ): void {
		$token  = $this->token( true );
		$before = self::post_count();
		$res    = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'status' => 'draft', $field => $value ), $token );
		$this->assertSame( 403, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'cpub_field_not_allowed', $res->get_data()['code'] );
		$this->assertSame( $before, self::post_count(), 'nothing was created' );
	}

	/** @dataProvider forbidden_fields */
	public function test_a_field_outside_writable_fields_is_403_on_update( string $field, $value ): void {
		$token = $this->token( true );
		$id    = $this->draft_of_ours( $token );
		$res   = $this->api( 'POST', "/wp/v2/posts/{$id}", array( 'title' => 'Changed', $field => $value ), $token );
		$this->assertSame( 403, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'cpub_field_not_allowed', $res->get_data()['code'] );
		$post = get_post( $id );
		$this->assertSame( 'Ours', $post->post_title, 'nothing was saved' );
		$this->assertSame( '', $post->post_password );
		$this->assertSame( $this->editor, (int) $post->post_author );
		$this->assertFalse( is_sticky( $id ) );
		$this->assertSame( '', (string) get_post_meta( $id, '_wp_page_template', true ) );
	}

	public static function forbidden_fields(): array {
		return array(
			'meta'     => array( 'meta', array( 'footnotes' => '[]' ) ),
			'template' => array( 'template', 'page-full-width.php' ),
			'password' => array( 'password', 'secret' ),
			'author'   => array( 'author', 1 ),
			'sticky'   => array( 'sticky', true ),
		);
	}

	public function test_the_writable_fields_are_exactly_these(): void {
		// A new field here widens what every agency token can do: change it on purpose, with a test.
		$this->assertSame(
			array( 'title', 'content', 'excerpt', 'status', 'categories', 'tags', 'featured_media', 'date', 'date_gmt' ),
			\CPub\Connector\Auth\RouteGate::WRITABLE_FIELDS['posts']
		);
	}

	// ------------------------------------------------------------ status

	public function test_private_is_403_with_or_without_the_publish_grant(): void {
		foreach ( array( 'without' => false, 'with' => true ) as $label => $can_publish ) {
			$token  = $this->token( $can_publish );
			$before = self::post_count();
			$res    = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'status' => 'private' ), $token );
			$this->assertSame( 403, $res->get_status(), "create, $label publish grant" );
			$this->assertSame( $before, self::post_count(), "create, $label publish grant" );

			$id  = $this->draft_of_ours( $token );
			$res = $this->api( 'POST', "/wp/v2/posts/{$id}", array( 'status' => 'private' ), $token );
			$this->assertSame( 403, $res->get_status(), "update, $label publish grant" );
			$this->assertSame( 'draft', get_post_status( $id ), "update, $label publish grant" );
		}
	}

	public function test_publishing_without_the_publish_grant_is_403(): void {
		$token = $this->token( false );
		foreach ( array( 'publish', 'future' ) as $status ) {
			$params = array( 'title' => 'T', 'status' => $status ) + ( 'future' === $status ? array( 'date' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) ) : array() );
			$before = self::post_count();
			$res    = $this->api( 'POST', '/wp/v2/posts', $params, $token );
			$this->assertSame( 403, $res->get_status(), "create $status" );
			$this->assertSame( $before, self::post_count(), "create $status" );

			$id  = $this->draft_of_ours( $token );
			$res = $this->api( 'POST', "/wp/v2/posts/{$id}", array( 'status' => $status ) + array_diff_key( $params, array( 'title' => 1, 'status' => 1 ) ), $token );
			$this->assertSame( 403, $res->get_status(), "update $status" );
			$this->assertSame( 'draft', get_post_status( $id ), "update $status" );
		}
		// The same request works once the site allows publishing: the refusal is the grant, not the role.
		$token = $this->token( true );
		$this->assertSame( 201, $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'status' => 'publish' ), $token )->get_status() );
	}

	/**
	 * Publishing is blocked by two layers, so either alone still holds:
	 * Sandbox withholds publish_posts, and RouteGate::drafts_only refuses the status.
	 */
	public function test_each_publishing_layer_holds_on_its_own(): void {
		$token = $this->token( false );
		$this->api( 'GET', '/cpub-connector/v1/connection', array(), $token );
		$this->assertSame( $this->editor, get_current_user_id() );
		$this->assertFalse( current_user_can( 'publish_posts' ), 'Sandbox: an Editor without the publish grant' );

		$prepared              = new stdClass();
		$prepared->post_status = 'publish';
		$result                = \CPub\Connector\Auth\RouteGate::drafts_only( $prepared, new WP_REST_Request( 'POST', '/wp/v2/posts' ) );
		$this->assertWPError( $result, 'RouteGate' );
		$this->assertSame( 'cpub_drafts_only', $result->get_error_code() );

		$token = $this->token( true );
		$this->api( 'GET', '/cpub-connector/v1/connection', array(), $token );
		$this->assertTrue( current_user_can( 'publish_posts' ) );
		$this->assertSame( $prepared, \CPub\Connector\Auth\RouteGate::drafts_only( $prepared, new WP_REST_Request( 'POST', '/wp/v2/posts' ) ) );
	}

	// ------------------------------------------------------------ outside the REST API

	/**
	 * admin-ajax.php?rest_route=… is not a REST request: the token signs no one in.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_token_sent_to_admin_ajax_with_rest_route_is_not_authenticated(): void {
		$token = $this->connect( array( 'post_as' => (string) $this->editor ) )['access_token'];
		$this->assertFalse( defined( 'REST_REQUEST' ), 'This test needs REST_REQUEST undefined.' );

		$_SERVER['REQUEST_URI']        = '/wp-admin/admin-ajax.php?rest_route=/wp/v2/posts';
		$_GET                          = array( 'rest_route' => '/wp/v2/posts', 'action' => 'x' );
		$_REQUEST                      = $_GET;
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		BearerAuth::reset();
		$GLOBALS['current_user'] = null;

		$this->assertSame( 0, get_current_user_id() );
		$this->assertNull( BearerAuth::grant() );
		$this->assertFalse( current_user_can( 'edit_posts' ) );
	}

	// ------------------------------------------------------------ uploads

	public function test_a_non_image_upload_is_refused(): void {
		$token = $this->token();
		$this->ignore_header_warnings();
		$files = array(
			'php named .png' => array( 'photo.png', '<?php echo "hi";' ),
			'html named .jpg' => array( 'photo.jpg', '<html><script>alert(1)</script></html>' ),
			'plain text'     => array( 'notes.txt', 'plain text' ),
			'pdf'            => array( 'doc.pdf', "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF" ),
			'svg'            => array( 'logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ),
		);
		foreach ( $files as $label => [ $name, $bytes ] ) {
			$before = (int) wp_count_posts( 'attachment' )->inherit;
			$this->use_token( $token );
			$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
			$request->set_header( 'Content-Type', 'application/octet-stream' );
			$request->set_header( 'Content-Disposition', 'attachment; filename="' . $name . '"' );
			$request->set_body( $bytes );
			$res = rest_do_request( $request );
			$this->assertGreaterThanOrEqual( 400, $res->get_status(), $label );
			wp_cache_delete( _count_posts_cache_key( 'attachment' ), 'counts' );
			$this->assertSame( $before, (int) wp_count_posts( 'attachment' )->inherit, "$label: no attachment" );
		}
	}

	// ------------------------------------------------------------ token replay

	/**
	 * A used refresh token presented again revokes the connection.
	 *
	 * The one exception (RefreshTokenRepository, ReissueAndIssuerTest): within
	 * TokenStore::REISSUE_GRACE seconds, while the replacement was never used,
	 * the agency probably lost our reply, so one reissue is allowed. These cases
	 * are outside that exception.
	 */
	public function test_reusing_a_refresh_token_revokes_the_connection(): void {
		global $wpdb;
		$cases = array(
			'after the replacement was used'     => function ( array $first ) {
				$second = $this->refresh( $first['refresh_token'] )->get_data();
				return $this->refresh( $second['refresh_token'] )->get_data();
			},
			'after the grace window'             => function ( array $first ) use ( $wpdb ) {
				$second = $this->refresh( $first['refresh_token'] )->get_data();
				$wpdb->query( 'UPDATE ' . \CPub\Connector\Installer::table( 'tokens' ) . " SET used_at = '2000-01-01 00:00:00' WHERE used_at IS NOT NULL AND kind = 'refresh'" );
				return $second;
			},
			'a third time (one reissue at most)' => function ( array $first ) {
				$this->refresh( $first['refresh_token'] );
				return $this->refresh( $first['refresh_token'] )->get_data();
			},
		);
		foreach ( $cases as $label => $renew ) {
			$first  = $this->connect( array( 'post_as' => (string) $this->editor ) );
			$latest = $renew( $first );
			$this->assertNotNull( Grants::active(), "$label: still connected before the replay" );

			$this->assertSame( 400, $this->refresh( $first['refresh_token'] )->get_status(), $label );
			$this->assertNull( Grants::active(), $label );
			$this->assertSame( 'refresh_token_reuse', self::revoked_reason(), $label );
			$this->assertSame( 401, $this->api( 'GET', '/cpub-connector/v1/connection', array(), $latest['access_token'] )->get_status(), "$label: the newest access token is dead" );
			$this->assertSame( 400, $this->refresh( $latest['refresh_token'] )->get_status(), "$label: the newest refresh token is dead" );
		}
	}

	public function test_reusing_an_authorization_code_revokes_the_connection(): void {
		$tokens = $this->connect( array( 'post_as' => (string) $this->editor ) );
		$this->assertSame( 400, $this->exchange( $tokens['code'], $tokens['verifier'] )->get_status() );
		$this->assertNull( Grants::active() );
		$this->assertSame( 'code_reuse', self::revoked_reason() );
		$this->assertSame( 401, $this->api( 'GET', '/cpub-connector/v1/connection', array(), $tokens['access_token'] )->get_status() );
		$this->assertSame( 400, $this->refresh( $tokens['refresh_token'] )->get_status() );
	}

	// ------------------------------------------------------------ the chosen person

	public function test_the_token_stops_working_when_the_chosen_person_is_made_administrator(): void {
		$token = $this->token( true );
		$this->assertSame( 200, $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token )->get_status() );

		get_userdata( $this->editor )->set_role( 'administrator' );

		$res = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'status' => 'draft' ), $token );
		$this->assertSame( 401, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 0, get_current_user_id(), 'nobody is signed in by the token' );
		$this->assertNull( Grants::active(), 'the connection is revoked, not only refused this once' );
		$this->assertSame( 'user_not_allowed', self::revoked_reason() );
		// Demoting them again doesn't bring the old token back.
		get_userdata( $this->editor )->set_role( 'editor' );
		$this->assertSame( 401, $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token )->get_status() );
	}

	public function test_the_token_stops_working_when_the_chosen_person_gets_administrator_powers_another_way(): void {
		$token = $this->token();
		get_userdata( $this->editor )->add_cap( 'manage_options' ); // e.g. a role-editor plugin
		$this->assertSame( 401, $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token )->get_status() );
		$this->assertNull( Grants::active() );
	}
}
