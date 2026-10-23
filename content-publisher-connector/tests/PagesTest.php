<?php

use CPub\Connector\Auth\RouteGate;
use CPub\Connector\PublisherUser;
use CPub\Connector\Rest\Ownership;

/**
 * The pages:write scope: pages under the same rules as posts. Only the pages the
 * agency created, a field allowlist, drafts unless the site allows publishing.
 * The dedicated Agency Publisher account (an Author) may write pages for token
 * requests only.
 */
class PagesTest extends OAuthTestCase {

	private int $editor;

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/** A token with pages:write, posting as $post_as ('agency': the dedicated account). */
	private function token( string $post_as = 'agency', bool $can_publish = false ): string {
		$fields = array( 'post_as' => $post_as ) + ( $can_publish ? array( 'can_publish' => '1' ) : array() );
		return $this->connect( $fields, array( 'scope' => self::SCOPES . ' pages:write' ) )['access_token'];
	}

	private function page_of_ours( string $token, array $params = array() ): int {
		$res = $this->api( 'POST', '/wp/v2/pages', $params + array( 'title' => 'Ours', 'status' => 'draft' ), $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return (int) $res->get_data()['id'];
	}

	private static function page_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page'" );
	}

	// ------------------------------------------------------------ the scope

	public function test_pages_are_refused_without_the_scope(): void {
		$token = $this->connect( array( 'post_as' => (string) $this->editor ) )['access_token'];
		foreach ( array( array( 'GET', '/wp/v2/pages' ), array( 'POST', '/wp/v2/pages' ) ) as [ $method, $route ] ) {
			$res = $this->api( $method, $route, array( 'title' => 'T' ), $token );
			$this->assertSame( 403, $res->get_status(), "$method $route" );
			$this->assertSame( 'cpub_forbidden', $res->get_data()['code'], "$method $route" );
		}
		$this->assertSame( 0, self::page_count() );
	}

	public function test_the_consent_screen_lists_pages(): void {
		$html = $this->consent( $this->authorize_query( array( 'scope' => self::SCOPES . ' pages:write' ) ) )['html'];
		$this->assertStringContainsString( 'data-scope="pages:write"', $html );
		$this->assertStringContainsString( 'Pages need an Editor or the separate account', $html );
		$this->assertStringNotContainsString( 'Pages need an Editor', $this->consent( $this->authorize_query() )['html'] );
	}

	// ------------------------------------------------------------ the dedicated account

	public function test_the_agency_account_creates_and_edits_its_own_draft_pages(): void {
		$token = $this->token();
		$id    = $this->page_of_ours( $token );
		$this->assertTrue( Ownership::is_ours( $id ), 'marked as the agency\'s' );
		$this->assertSame( PublisherUser::get()->ID, (int) get_post( $id )->post_author );

		$res = $this->api( 'POST', "/wp/v2/pages/{$id}", array( 'title' => 'Ours, corrected' ), $token );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'Ours, corrected', get_post( $id )->post_title );
		$this->assertSame( 200, $this->api( 'GET', "/wp/v2/pages/{$id}", array( 'context' => 'edit' ), $token )->get_status() );
	}

	public function test_the_agency_account_gets_page_rights_only_during_token_requests(): void {
		$token = $this->token();
		$this->page_of_ours( $token );
		$user = PublisherUser::get();
		$this->assertSame( array( PublisherUser::ROLE ), array_values( $user->roles ), 'still an Author' );

		$this->use_token( null );
		$this->assertFalse( user_can( $user->ID, 'edit_pages' ), 'no page rights outside a token request' );
		$this->assertFalse( PublisherUser::too_powerful( $user ) );
		// And the connection keeps working.
		$this->assertSame( 200, $this->api( 'GET', '/wp/v2/pages', array(), $token )->get_status() );
	}

	public function test_a_site_author_can_not_write_pages(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$token  = $this->token( (string) $author );
		$res    = $this->api( 'POST', '/wp/v2/pages', array( 'title' => 'T', 'status' => 'draft' ), $token );
		$this->assertSame( 403, $res->get_status(), 'WordPress doesn\'t let Authors write pages, and the token adds nothing' );
		$this->assertSame( 0, self::page_count() );
		$this->assertSame( 201, $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'status' => 'draft' ), $token )->get_status(), 'posts still work' );
	}

	public function test_a_site_editor_writes_pages(): void {
		$token = $this->token( (string) $this->editor );
		$id    = $this->page_of_ours( $token );
		$this->assertSame( $this->editor, (int) get_post( $id )->post_author );
	}

	// ------------------------------------------------------------ pages the agency didn't create

	public function test_editing_a_page_the_agency_did_not_create_is_403(): void {
		$token = $this->token( (string) $this->editor, true );
		foreach ( array( 'draft', 'pending', 'publish' ) as $status ) {
			$theirs = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $this->editor, 'post_status' => $status, 'post_title' => "Site's $status" ) );
			$res    = $this->api( 'POST', "/wp/v2/pages/{$theirs}", array( 'title' => 'Changed by the agency' ), $token );
			$this->assertSame( 403, $res->get_status(), $status );
			$this->assertSame( "Site's $status", get_post( $theirs )->post_title, $status );
			$this->assertSame( 403, $this->api( 'DELETE', "/wp/v2/pages/{$theirs}", array(), $token )->get_status(), "delete $status" );
			$this->assertSame( $status, get_post_status( $theirs ), "delete $status" );
		}
	}

	public function test_others_draft_pages_are_not_readable_or_listed(): void {
		$token  = $this->token();
		$theirs = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $this->editor, 'post_status' => 'draft', 'post_title' => 'Secret page' ) );
		$ours   = $this->page_of_ours( $token );
		$this->assertNotSame( 200, $this->api( 'GET', "/wp/v2/pages/{$theirs}", array( 'context' => 'edit' ), $token )->get_status() );
		$list = $this->api( 'GET', '/wp/v2/pages', array( 'status' => 'draft', 'context' => 'edit' ), $token );
		$this->assertSame( 200, $list->get_status(), wp_json_encode( $list->get_data() ) );
		$ids = array_column( (array) $list->get_data(), 'id' );
		$this->assertContains( $ours, $ids );
		$this->assertNotContains( $theirs, $ids );
		$this->assertSame( '1', (string) $list->get_headers()['X-WP-Total'], 'not counted either' );
	}

	// ------------------------------------------------------------ fields

	/** @dataProvider forbidden_fields */
	public function test_a_field_outside_writable_fields_is_403( string $field, $value ): void {
		$token  = $this->token( 'agency', true );
		$before = self::page_count();
		$res    = $this->api( 'POST', '/wp/v2/pages', array( 'title' => 'T', 'status' => 'draft', $field => $value ), $token );
		$this->assertSame( 403, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'cpub_field_not_allowed', $res->get_data()['code'] );
		$this->assertSame( $before, self::page_count(), 'nothing was created' );

		$id  = $this->page_of_ours( $token );
		$res = $this->api( 'POST', "/wp/v2/pages/{$id}", array( 'title' => 'Changed', $field => $value ), $token );
		$this->assertSame( 'cpub_field_not_allowed', $res->get_data()['code'], 'on update too' );
		$page = get_post( $id );
		$this->assertSame( 'Ours', $page->post_title, 'nothing was saved' );
		$this->assertSame( 0, (int) $page->menu_order );
		$this->assertSame( '', $page->post_password );
		$this->assertSame( '', (string) get_post_meta( $id, '_wp_page_template', true ) );
	}

	public static function forbidden_fields(): array {
		return array(
			'template'   => array( 'template', 'page-full-width.php' ),
			'menu_order' => array( 'menu_order', 5 ),
			'meta'       => array( 'meta', array( 'footnotes' => '[]' ) ),
			'password'   => array( 'password', 'secret' ),
			'author'     => array( 'author', 1 ),
			'comments'   => array( 'comment_status', 'open' ),
		);
	}

	public function test_the_writable_fields_are_exactly_these(): void {
		// A new field here widens what every agency token can do: change it on purpose, with a test.
		$this->assertSame(
			array( 'title', 'content', 'excerpt', 'status', 'parent', 'featured_media', 'date', 'date_gmt' ),
			RouteGate::WRITABLE_FIELDS['pages']
		);
	}

	public function test_a_page_goes_under_a_published_page_or_one_of_ours_only(): void {
		$token     = $this->token();
		$published = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $this->editor, 'post_status' => 'publish' ) );
		$draft     = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $this->editor, 'post_status' => 'draft' ) );
		$private   = self::factory()->post->create( array( 'post_type' => 'page', 'post_author' => $this->editor, 'post_status' => 'private' ) );
		$a_post    = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'publish' ) );

		$under = $this->page_of_ours( $token, array( 'parent' => $published ) );
		$this->assertSame( $published, (int) get_post( $under )->post_parent );
		$child = $this->page_of_ours( $token, array( 'parent' => $under ) );
		$this->assertSame( $under, (int) get_post( $child )->post_parent, 'under a draft of ours' );

		foreach ( array( 'a draft of theirs' => $draft, 'a private page' => $private, 'a post' => $a_post ) as $label => $parent ) {
			$before = self::page_count();
			$res    = $this->api( 'POST', '/wp/v2/pages', array( 'title' => 'T', 'status' => 'draft', 'parent' => $parent ), $token );
			$this->assertSame( 403, $res->get_status(), $label );
			$this->assertSame( 'cpub_parent_not_allowed', $res->get_data()['code'], $label );
			$this->assertSame( $before, self::page_count(), $label );
		}
		$res = $this->api( 'POST', "/wp/v2/pages/{$under}", array( 'parent' => $draft ), $token );
		$this->assertSame( 'cpub_parent_not_allowed', $res->get_data()['code'], 'moving one of ours' );
		$this->assertSame( $published, (int) get_post( $under )->post_parent );
		$this->assertSame( 'draft', get_post_status( $draft ), 'the parent is never changed' );
	}

	// ------------------------------------------------------------ status

	public function test_publishing_a_page_needs_the_publish_grant(): void {
		$token = $this->token();
		foreach ( array( 'publish', 'future' ) as $status ) {
			$params = array( 'title' => 'T', 'status' => $status ) + ( 'future' === $status ? array( 'date' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) ) : array() );
			$before = self::page_count();
			$this->assertSame( 403, $this->api( 'POST', '/wp/v2/pages', $params, $token )->get_status(), $status );
			$this->assertSame( $before, self::page_count(), $status );
		}
		$id = $this->page_of_ours( $token );
		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/pages/{$id}", array( 'status' => 'publish' ), $token )->get_status() );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_with_the_publish_grant_it_publishes_corrects_and_unpublishes_its_own_pages(): void {
		$token = $this->token( 'agency', true );
		$res   = $this->api( 'POST', '/wp/v2/pages', array( 'title' => 'Live', 'status' => 'publish' ), $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$id = (int) $res->get_data()['id'];
		$this->assertSame( 'publish', get_post_status( $id ) );

		$this->assertSame( 200, $this->api( 'POST', "/wp/v2/pages/{$id}", array( 'title' => 'Live, corrected' ), $token )->get_status() );
		$this->assertSame( 'Live, corrected', get_post( $id )->post_title );
		$this->assertSame( 200, $this->api( 'POST', "/wp/v2/pages/{$id}", array( 'status' => 'draft' ), $token )->get_status() );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	public function test_private_is_403_with_or_without_the_publish_grant(): void {
		foreach ( array( 'without' => false, 'with' => true ) as $label => $can_publish ) {
			$token  = $this->token( 'agency', $can_publish );
			$before = self::page_count();
			$this->assertSame( 403, $this->api( 'POST', '/wp/v2/pages', array( 'title' => 'T', 'status' => 'private' ), $token )->get_status(), "create, $label publish grant" );
			$this->assertSame( $before, self::page_count() );
		}
	}

	// ------------------------------------------------------------ duplicates

	public function test_a_retried_page_is_not_created_twice(): void {
		$token = $this->token();
		$key   = 'page-1-' . str_repeat( 'p', 20 );
		$this->ignore_header_warnings();
		$send = function () use ( $token, $key ) {
			$this->use_token( $token );
			$request = new WP_REST_Request( 'POST', '/wp/v2/pages' );
			$request->set_param( 'title', 'Once' );
			$request->set_param( 'status', 'draft' );
			$request->set_header( 'X-CPub-Idempotency-Key', $key );
			return rest_do_request( $request );
		};
		$first = $send();
		$this->assertSame( 201, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$count  = self::page_count();
		$second = $send();
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $first->get_data()['id'], $second->get_data()['id'] );
		$this->assertSame( '1', $second->get_headers()['X-CPub-Existing'] );
		$this->assertSame( $count, self::page_count() );
	}
}
