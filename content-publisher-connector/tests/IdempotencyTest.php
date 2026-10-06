<?php

/** A retried create with the same key returns what the first one made. */
class IdempotencyTest extends OAuthTestCase {

	private function create( string $route, string $token, ?string $key, array $params = array(), ?string $body = null, array $headers = array() ): WP_REST_Response {
		$this->ignore_header_warnings();
		$this->use_token( $token );
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		if ( null !== $key ) {
			$request->set_header( 'X-CPub-Idempotency-Key', $key );
		}
		foreach ( $headers as $k => $v ) {
			$request->set_header( $k, $v );
		}
		if ( null !== $body ) {
			$request->set_body( $body );
		}
		return rest_do_request( $request );
	}

	private function post_count(): int {
		return count( get_posts( array( 'post_type' => 'post', 'post_status' => array( 'any', 'trash' ), 'numberposts' => -1, 'fields' => 'ids' ) ) );
	}

	public function test_a_retried_draft_is_not_created_twice() {
		$token  = $this->connect()['access_token'];
		$key    = 'job-12-' . str_repeat( 'a', 20 );
		$first  = $this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'Hello', 'status' => 'draft' ) );
		$this->assertSame( 201, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$count  = $this->post_count();
		$second = $this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'Hello', 'status' => 'draft' ) );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first->get_data()['id'], $second->get_data()['id'] );
		$this->assertSame( '1', $second->get_headers()['X-CPub-Existing'] );
		$this->assertSame( $count, $this->post_count() );

		// A different key is a different post; no key behaves as before.
		$this->assertSame( 201, $this->create( '/wp/v2/posts', $token, 'job-13-' . str_repeat( 'b', 20 ), array( 'title' => 'Other', 'status' => 'draft' ) )->get_status() );
		$this->assertSame( 201, $this->create( '/wp/v2/posts', $token, null, array( 'title' => 'No key', 'status' => 'draft' ) )->get_status() );
	}

	public function test_a_draft_the_client_binned_is_still_found() {
		$token = $this->connect()['access_token'];
		$key   = 'job-14-' . str_repeat( 'c', 20 );
		$id    = $this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'T', 'status' => 'draft' ) )->get_data()['id'];
		wp_trash_post( $id );
		$again = $this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'T', 'status' => 'draft' ) );
		$this->assertSame( $id, $again->get_data()['id'] );
		$this->assertSame( 'trash', $again->get_data()['status'] );
	}

	public function test_the_key_is_saved_even_when_the_request_dies_after_the_insert() {
		$token = $this->connect()['access_token'];
		$key   = 'job-18-' . str_repeat( 'h', 20 );
		// The request dies (a timeout, a fatal in another plugin) after the post is inserted but before
		// WordPress finishes the request.
		$die = function () {
			throw new RuntimeException( 'died mid-request' );
		};
		add_action( 'rest_insert_post', $die );
		try {
			$this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'T', 'status' => 'draft' ) );
			$this->fail( 'expected the request to die' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'died mid-request', $e->getMessage() );
		}
		remove_action( 'rest_insert_post', $die );
		$count = $this->post_count();
		$this->assertSame( 1, $count, 'the half-made post was left behind' );
		$again = $this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'T', 'status' => 'draft' ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $count, $this->post_count(), 'the retry reused the half-made post' );
	}

	public function test_media_uploads_are_protected_too() {
		$token = $this->connect()['access_token'];
		$png   = file_get_contents( DIR_TESTDATA . '/images/test-image.png' );
		$hdrs  = array( 'Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="a.png"' );
		$key   = 'asset-7-' . str_repeat( 'd', 20 );
		$one   = $this->create( '/wp/v2/media', $token, $key, array(), $png, $hdrs );
		$this->assertSame( 201, $one->get_status(), wp_json_encode( $one->get_data() ) );
		$two = $this->create( '/wp/v2/media', $token, $key, array(), $png, $hdrs );
		$this->assertSame( 200, $two->get_status() );
		$this->assertSame( $one->get_data()['id'], $two->get_data()['id'] );
	}

	public function test_malformed_keys_are_refused_and_other_users_posts_are_not_returned() {
		$token = $this->connect()['access_token'];
		foreach ( array( 'short', str_repeat( 'x', 65 ), "bad key with spaces 1234", "k'--" . str_repeat( 'x', 20 ) ) as $bad ) {
			$this->assertSame( 400, $this->create( '/wp/v2/posts', $token, $bad, array( 'title' => 'T', 'status' => 'draft' ) )->get_status(), $bad );
		}
		// A post by someone else that happens to carry the key is never handed to the agency.
		$key   = 'job-15-' . str_repeat( 'e', 20 );
		$other = self::factory()->post->create( array( 'post_status' => 'draft', 'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
		update_post_meta( $other, \CPub\Connector\Rest\Idempotency::META_KEY, $key );
		$res = $this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'Mine', 'status' => 'draft' ) );
		$this->assertSame( 201, $res->get_status() );
		$this->assertNotSame( $other, $res->get_data()['id'] );
	}

	public function test_the_key_is_ignored_outside_token_requests_and_on_updates() {
		$token = $this->connect()['access_token'];
		$key   = 'job-16-' . str_repeat( 'f', 20 );
		$id    = $this->create( '/wp/v2/posts', $token, $key, array( 'title' => 'T', 'status' => 'draft' ) )->get_data()['id'];
		$upd   = $this->create( "/wp/v2/posts/{$id}", $token, 'job-17-' . str_repeat( 'g', 20 ), array( 'title' => 'Changed' ) );
		$this->assertSame( 200, $upd->get_status() );
		$this->assertSame( $key, get_post_meta( $id, \CPub\Connector\Rest\Idempotency::META_KEY, true ) );
		// A logged-in admin (no token) creating with a key: plain WordPress behaviour.
		$this->use_token( null );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$req = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$req->set_header( 'X-CPub-Idempotency-Key', $key );
		$req->set_param( 'title', 'Admin' );
		$res = rest_do_request( $req );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( '', get_post_meta( $res->get_data()['id'], \CPub\Connector\Rest\Idempotency::META_KEY, true ) );
	}
}
