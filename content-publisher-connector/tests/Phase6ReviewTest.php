<?php

use CPub\Connector\OAuth\Grants;
use CPub\Connector\Rest\Idempotency;
use CPub\Connector\Rest\Ownership;

/** Regression tests for the Phase 6 review findings (Connector). */
class Phase6ReviewTest extends OAuthTestCase {

	private int $editor;
	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Sara Editor', 'user_login' => 'sara_login', 'user_email' => 'sara.secret@client.test' ) );
		$this->author = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	private function token_as( int $user_id, bool $can_publish = false, bool $tags = false ): string {
		$fields = array( 'post_as' => (string) $user_id ) + ( $can_publish ? array( 'can_publish' => '1' ) : array() );
		return $this->connect( $fields, $tags ? array( 'scope' => self::SCOPES . ' terms:write' ) : array() )['access_token'];
	}

	private function our_image( string $token ): int {
		$this->ignore_header_warnings();
		$this->use_token( $token );
		$req = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$req->set_header( 'Content-Type', 'image/png' );
		$req->set_header( 'Content-Disposition', 'attachment; filename="a.png"' );
		$req->set_body( file_get_contents( DIR_TESTDATA . '/images/test-image.png' ) );
		$res = rest_do_request( $req );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return (int) $res->get_data()['id'];
	}

	// 5. Listings count only the agency's own non-public posts: no probing others' drafts by search.
	public function test_searching_drafts_reveals_nothing_about_others_posts() {
		self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft', 'post_title' => 'Merger with Acme secret' ) );
		self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'private', 'post_title' => 'Sara private Acme notes' ) );
		$token = $this->token_as( $this->editor );
		foreach ( array( 'draft', 'private', 'any', 'draft,private' ) as $status ) {
			$res = $this->api( 'GET', '/wp/v2/posts', array( 'status' => $status, 'search' => 'Acme' ), $token );
			$total = (int) ( $res->get_headers()['X-WP-Total'] ?? -1 );
			$this->assertSame( 0, max( 0, $total ), "status=$status leaked a count" );
			$this->assertSame( array(), (array) ( 200 === $res->get_status() ? $res->get_data() : array() ) );
		}
		// The agency's own drafts are still listed.
		$this->api( 'POST', '/wp/v2/posts', array( 'title' => 'Our Acme draft', 'status' => 'draft' ), $token );
		$res = $this->api( 'GET', '/wp/v2/posts', array( 'status' => 'draft', 'search' => 'Acme' ), $token );
		$this->assertSame( 1, (int) $res->get_headers()['X-WP-Total'] );
	}

	// 6. The token sees only the id and name of the person it posts as.
	public function test_the_persons_login_and_email_are_not_exposed() {
		$token = $this->token_as( $this->editor );
		$this->assertSame( 403, $this->api( 'GET', '/wp/v2/users/me', array( 'context' => 'edit' ), $token )->get_status() );
		$res  = $this->api( 'GET', '/wp/v2/users/me', array(), $token );
		$json = wp_json_encode( $res->get_data() ) . wp_json_encode( $res->get_links() );
		$this->assertSame( array( 'id' => $this->editor, 'name' => 'Sara Editor' ), $res->get_data() );
		$this->assertStringNotContainsString( 'sara_login', $json );
		$this->assertStringNotContainsString( 'sara.secret', $json );
		// Signed in normally, WordPress's own answer is untouched.
		$this->use_token( null );
		wp_set_current_user( $this->editor );
		$this->assertArrayHasKey( 'slug', rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) )->get_data() );
	}

	// 7. The agency can't change an image the site's own live posts use, even when it may publish.
	public function test_images_in_the_sites_own_live_posts_stay_locked() {
		$token = $this->token_as( $this->author, true );
		$img   = $this->our_image( $token );
		$theirs = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'publish', 'post_content' => '<!-- wp:image {"id":' . $img . '} --><img class="wp-image-' . $img . '"/><!-- /wp:image -->' ) );
		$this->assertSame( 403, $this->api( 'DELETE', "/wp/v2/media/{$img}", array( 'force' => true ), $token )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/media/{$img}", array( 'alt_text' => 'x' ), $token )->get_status() );
		$this->assertNotNull( get_post( $img ) );
		// In the agency's own live post only: it may.
		wp_update_post( array( 'ID' => $theirs, 'post_content' => 'no image now' ) );
		$ours = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'Ours', 'status' => 'publish', 'content' => '<img class="wp-image-' . $img . '"/>' ), $token );
		$this->assertSame( 201, $ours->get_status() );
		$this->assertSame( 200, $this->api( 'POST', "/wp/v2/media/{$img}", array( 'alt_text' => 'Better alt' ), $token )->get_status() );
	}

	// 8. The featured image must be one the agency uploaded.
	public function test_the_featured_image_must_be_the_agencys_own() {
		$token   = $this->token_as( $this->author, true );
		$private = self::factory()->attachment->create_object( array( 'file' => 'embargo.jpg', 'post_author' => $this->editor, 'post_mime_type' => 'image/jpeg', 'post_parent' => self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'draft' ) ) ) );
		$res     = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'status' => 'publish', 'featured_media' => $private ), $token );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'cpub_featured_not_ours', $res->get_data()['code'] );
		$ok = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'status' => 'draft', 'featured_media' => $this->our_image( $token ) ), $token );
		$this->assertSame( 201, $ok->get_status() );
	}

	// 9. No extra term powers: tags are created through the route rule, nothing more.
	public function test_tag_creation_grants_no_term_management() {
		$token = $this->token_as( $this->author, false, true );
		$this->assertSame( 201, $this->api( 'POST', '/wp/v2/tags', array( 'name' => 'Fresh' ), $token )->get_status() );
		$this->assertFalse( current_user_can( 'manage_categories' ) );
		$this->assertFalse( current_user_can( 'edit_post_tags' ) );
		$this->assertNotSame( 200, $this->api( 'GET', '/wp/v2/tags', array( 'context' => 'edit' ), $token )->get_status() );
	}

	// 10. Page-level checks are limited like post-level ones.
	public function test_page_capabilities_are_sandboxed_too() {
		$own   = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'draft' ) );
		$token = $this->token_as( $this->editor );
		$this->api( 'GET', '/cpub-connector/v1/connection', array(), $token );
		foreach ( array( 'edit_post', 'edit_page', 'delete_page', 'read_page', 'delete_post' ) as $cap ) {
			$this->assertFalse( current_user_can( $cap, $own ), $cap );
		}
	}

	// 11. A copy of the agency's post (a duplicate-post plugin copies all meta) isn't the agency's.
	public function test_a_copied_post_is_not_the_agencys() {
		$token = $this->token_as( $this->editor, true );
		$key   = 'job-99-' . str_repeat( 'k', 20 );
		$this->ignore_header_warnings();
		$this->use_token( $token );
		$req = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$req->set_param( 'title', 'Original' );
		$req->set_param( 'status', 'draft' );
		$req->set_header( 'X-CPub-Idempotency-Key', $key );
		$orig = rest_do_request( $req )->get_data()['id'];
		$this->assertTrue( Ownership::is_ours( $orig ) );

		// The chosen person duplicates it, meta and all.
		$copy = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'draft', 'post_title' => 'Copy' ) );
		foreach ( array( Ownership::META_KEY, Idempotency::META_KEY ) as $k ) {
			update_post_meta( $copy, $k, get_post_meta( $orig, $k, true ) );
		}
		$this->assertFalse( Ownership::is_ours( $copy ) );
		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/posts/{$copy}", array( 'status' => 'publish' ), $token )->get_status() );
		$this->assertSame( 'draft', get_post_status( $copy ) );
		// A retry with the key finds the original, not the copy.
		$this->use_token( $token );
		$again = rest_do_request( $req );
		$this->assertSame( $orig, $again->get_data()['id'] );
		// Marks written by hand (no signature) don't count either.
		update_post_meta( $copy, Ownership::META_KEY, 'v1:' . Grants::active()->id . ':' . str_repeat( 'a', 64 ) );
		$this->assertFalse( Ownership::is_ours( $copy ) );
	}

	// Mutation-check gaps: each rule on its own.
	public function test_the_token_never_has_a_right_the_person_lacks() {
		get_userdata( $this->editor )->add_cap( 'publish_posts', false ); // a role plugin took it away
		$this->assertTrue( \CPub\Connector\PublisherUser::may_post_as( get_userdata( $this->editor ) ) );
		$token = $this->token_as( $this->editor, true );
		$this->assertSame( 403, $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'X', 'status' => 'publish' ), $token )->get_status() );
		$this->assertFalse( current_user_can( 'publish_posts' ) );
	}

	public function test_the_gate_alone_refuses_changes_to_posts_that_arent_ours() {
		$this->token_as( $this->author, true );
		$grant  = Grants::active();
		$theirs = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish' ) );
		$this->assertFalse( \CPub\Connector\Auth\RouteGate::may_change( $grant, $theirs ) );
		$draft = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'draft' ) );
		$this->assertTrue( \CPub\Connector\Auth\RouteGate::may_change( $grant, $draft ), 'drafts: the sandbox decides whose' );
	}

	public function test_a_retry_after_the_original_was_deleted_does_not_adopt_a_copy() {
		$token = $this->token_as( $this->editor );
		$key   = 'job-98-' . str_repeat( 'q', 20 );
		$this->ignore_header_warnings();
		$this->use_token( $token );
		$req = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$req->set_param( 'title', 'Original' );
		$req->set_param( 'status', 'draft' );
		$req->set_header( 'X-CPub-Idempotency-Key', $key );
		$orig = rest_do_request( $req )->get_data()['id'];
		$copy = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'draft' ) );
		foreach ( array( Ownership::META_KEY, Idempotency::META_KEY ) as $k ) {
			update_post_meta( $copy, $k, get_post_meta( $orig, $k, true ) );
		}
		wp_delete_post( $orig, true );
		$this->use_token( $token );
		$again = rest_do_request( $req );
		$this->assertSame( 201, $again->get_status(), 'a new post, not the copy' );
		$this->assertNotSame( $copy, $again->get_data()['id'] );
	}

	public function test_only_the_author_and_editor_roles_qualify() {
		add_role( 'seo_writer', 'SEO writer', array( 'read' => true, 'edit_posts' => true, 'upload_files' => true, 'publish_posts' => true ) );
		$writer = self::factory()->user->create( array( 'role' => 'seo_writer' ) );
		$this->assertFalse( \CPub\Connector\PublisherUser::may_post_as( get_userdata( $writer ) ) );
		$both = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $both )->add_role( 'seo_writer' );
		$this->assertFalse( \CPub\Connector\PublisherUser::may_post_as( get_userdata( $both ) ), 'an extra role we don\'t know' );
		remove_role( 'seo_writer' );
	}

	// The mark is complete even for images (WordPress returns early for attachments).
	public function test_uploaded_images_carry_a_valid_mark() {
		$token = $this->token_as( $this->author );
		$this->assertTrue( Ownership::is_ours( $this->our_image( $token ) ) );
	}
}
