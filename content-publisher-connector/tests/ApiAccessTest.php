<?php

use CPub\Connector\ActivityLog;
use CPub\Connector\Admin\ConnectionActions;
use CPub\Connector\Installer;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\PublisherUser;

/** What a token can and can't do on the site's REST API. */
class ApiAccessTest extends OAuthTestCase {

	private string $token;

	public function set_up(): void {
		parent::set_up();
		$this->token = $this->connect()['access_token'];
	}

	public function test_signs_in_as_the_publisher_user(): void {
		$res = $this->api( 'GET', '/wp/v2/users/me', array(), $this->token );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( PublisherUser::get()->ID, $res->get_data()['id'] );
	}

	public function test_connection_endpoint(): void {
		$data = $this->api( 'GET', '/cpub-connector/v1/connection', array(), $this->token )->get_data();
		$this->assertSame( array( 'posts:write', 'media:write', 'terms:read', 'account:read' ), $data['scopes'] );
		$this->assertSame( 'author', $data['user']['role'] );
	}

	public function test_connection_endpoint_needs_a_token(): void {
		wp_set_current_user( $this->admin );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/cpub-connector/v1/connection' ) )->get_status() );
	}

	public function test_creates_a_draft(): void {
		$res = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'Hello', 'content' => '<p>Body</p>', 'status' => 'draft' ), $this->token );
		$this->assertSame( 201, $res->get_status() );
		$post = get_post( $res->get_data()['id'] );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( PublisherUser::get()->ID, (int) $post->post_author );
	}

	public function test_default_status_is_draft(): void {
		$res = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'No status' ), $this->token );
		$this->assertSame( 'draft', get_post_status( $res->get_data()['id'] ) );
	}

	/** @dataProvider live_statuses */
	public function test_cannot_publish_schedule_or_make_private( string $status ): void {
		$res = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'X', 'status' => $status, 'date' => '2099-01-01T00:00:00' ), $this->token );
		$this->assertSame( 403, $res->get_status() );
		// WordPress refuses first now (the sandbox withholds publish_posts); RouteGate would refuse too.
		$this->assertContains( $res->get_data()['code'], array( 'rest_cannot_publish', 'cpub_drafts_only' ) );
	}

	public static function live_statuses(): array {
		return array( 'publish' => array( 'publish' ), 'future' => array( 'future' ), 'private' => array( 'private' ) );
	}

	public function test_can_edit_own_draft_but_not_once_published(): void {
		$id = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'Draft' ), $this->token )->get_data()['id'];
		$this->assertSame( 200, $this->api( 'POST', '/wp/v2/posts/' . $id, array( 'title' => 'Draft v2' ), $this->token )->get_status() );

		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ); // the client's editor publishes it
		$res = $this->api( 'POST', '/wp/v2/posts/' . $id, array( 'title' => 'Sneaky change' ), $this->token );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'cpub_post_locked', $res->get_data()['code'] );
		$this->assertSame( 'Draft v2', get_post( $id )->post_title );
	}

	public function test_can_read_own_post_status_for_the_daily_check(): void {
		$id = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'Draft' ), $this->token )->get_data()['id'];
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
		$res = $this->api( 'GET', '/wp/v2/posts/' . $id, array( 'context' => 'edit' ), $this->token );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'publish', $res->get_data()['status'] );
	}

	public function test_cannot_edit_someone_elses_post(): void {
		$other = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => $this->admin ) );
		$this->assertSame( 403, $this->api( 'POST', '/wp/v2/posts/' . $other, array( 'title' => 'Hijack' ), $this->token )->get_status() );
	}

	/** @dataProvider forbidden_routes */
	public function test_everything_else_is_refused( string $method, string $route ): void {
		$res = $this->api( $method, $route, array(), $this->token );
		$this->assertSame( 403, $res->get_status(), "$method $route" );
		$this->assertSame( 'cpub_forbidden', $res->get_data()['code'], "$method $route" );
	}

	public static function forbidden_routes(): array {
		return array(
			'settings'         => array( 'GET', '/wp/v2/settings' ),
			'list users'       => array( 'GET', '/wp/v2/users' ),
			'edit own profile' => array( 'POST', '/wp/v2/users/me' ),
			'delete a post'    => array( 'DELETE', '/wp/v2/posts/1' ),
			'pages'            => array( 'POST', '/wp/v2/pages' ),
			'comments'         => array( 'GET', '/wp/v2/comments' ),
			'plugins'          => array( 'GET', '/wp/v2/plugins' ),
			'create category'  => array( 'POST', '/wp/v2/categories' ),
			'batch'            => array( 'POST', '/batch/v1' ),
			'app passwords'    => array( 'POST', '/wp/v2/users/me/application-passwords' ),
			'index'            => array( 'GET', '/' ),
		);
	}

	public function test_reads_categories_and_tags(): void {
		$this->assertSame( 200, $this->api( 'GET', '/wp/v2/categories', array(), $this->token )->get_status() );
		$this->assertSame( 200, $this->api( 'GET', '/wp/v2/tags', array(), $this->token )->get_status() );
	}

	public function test_invalid_token_is_401_not_anonymous(): void {
		$res = $this->api( 'GET', '/wp/v2/posts', array(), 'cpub_at_' . str_repeat( 'a', 80 ) );
		$this->assertSame( 401, $res->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_expired_access_token_is_refused(): void {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . Installer::table( 'tokens' ) . " SET expires_at = '2000-01-01 00:00:00' WHERE kind = 'access'" );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $this->token )->get_status() );
	}

	public function test_a_revoked_connection_refuses_tokens_even_if_token_rows_were_missed(): void {
		global $wpdb;
		// Defence in depth: the connection's status is checked on every request, not just the token row.
		$wpdb->query( 'UPDATE ' . Installer::table( 'grants' ) . " SET status = 'revoked'" );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $this->token )->get_status() );
	}

	public function test_other_plugins_bearer_tokens_are_ignored(): void {
		$this->use_token( 'eyJhbGciOiJIUzI1NiJ9.someone-elses-jwt' );
		$this->assertSame( 0, get_current_user_id() );
		$result = apply_filters( 'rest_authentication_errors', null );
		$this->assertFalse( is_wp_error( $result ) && str_starts_with( $result->get_error_code(), 'cpub_' ), 'Must not claim tokens that aren\'t ours.' );
	}

	public function test_backup_header_works_when_the_standard_one_is_stripped(): void {
		$this->use_token( $this->token, 'HTTP_X_CPUB_AUTHORIZATION' );
		$this->assertSame( PublisherUser::get()->ID, get_current_user_id() );
	}

	public function test_elevated_user_cuts_off_the_connection(): void {
		PublisherUser::get()->set_role( 'editor' );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $this->token )->get_status() );
		$this->assertNull( Grants::active() );
	}

	public function test_disconnect_button_revokes_immediately(): void {
		wp_set_current_user( $this->admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( ConnectionActions::ACTION );
		add_filter( 'wp_safe_redirect_fallback', fn( $u ) => $u );
		ConnectionActions::disconnect();
		$this->assertNull( Grants::active() );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $this->token )->get_status() );
	}

	public function test_disconnect_needs_an_administrator(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		ConnectionActions::disconnect();
	}

	public function test_deactivating_the_plugin_revokes(): void {
		Installer::deactivate();
		$this->assertNull( Grants::active() );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $this->token )->get_status() );
		Installer::activate();
	}

	public function test_requests_and_refusals_are_logged(): void {
		$this->api( 'GET', '/wp/v2/users/me', array(), $this->token );
		$this->api( 'GET', '/wp/v2/settings', array(), $this->token );
		$log = array_map( fn( $r ) => $r->action . ' ' . $r->status, ActivityLog::recent( 5 ) );
		$this->assertContains( 'GET /wp/v2/users/me 200', $log );
		$this->assertContains( 'GET /wp/v2/settings 403', $log );
	}
}
