<?php

use CPub\Connector\Admin\ConnectionActions;
use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\PublisherUser;
use CPub\Connector\Rest\Ownership;

/**
 * 0.5.0: a connection posts as one of the site's own Authors or Editors,
 * limited to the agency's own posts; publishing only when the site allows it;
 * creating tags.
 */
class ClientUserTest extends OAuthTestCase {

	private int $editor;
	private int $author;

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor', 'display_name' => 'Sara Editor' ) );
		$this->author = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Omar Author' ) );
	}

	private function token_as( int $user_id, bool $can_publish = false, bool $tags = false ): string {
		$fields = array( 'post_as' => (string) $user_id ) + ( $can_publish ? array( 'can_publish' => '1' ) : array() );
		$query  = $tags ? array( 'scope' => self::SCOPES . ' terms:write' ) : array();
		return $this->connect( $fields, $query )['access_token'];
	}

	private function create( string $token, array $params ): WP_REST_Response {
		return $this->api( 'POST', '/wp/v2/posts', $params, $token );
	}

	// ------------------------------------------------------------ choosing the person

	public function test_approval_screen_offers_authors_and_editors_only() {
		$sub  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$html = $this->consent( $this->authorize_query() )['html'];
		$this->assertStringContainsString( 'value="' . $this->editor . '"', $html );
		$this->assertStringContainsString( 'value="' . $this->author . '"', $html );
		$this->assertStringNotContainsString( 'value="' . $this->admin . '"', $html );
		$this->assertStringNotContainsString( 'value="' . $sub . '"', $html );
		$this->assertStringContainsString( 'value="agency"', $html );
		// An agency account left over from an earlier install isn't offered as a person, nor accepted.
		$old = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Old agency account' ) );
		update_user_meta( $old, PublisherUser::META, 1 );
		$this->assertStringNotContainsString( 'value="' . $old . '"', $this->consent( $this->authorize_query() )['html'] );
		$this->assertNull( $this->consent( $this->authorize_query(), 'approve', null, array( 'post_as' => (string) $old ) )['redirect'] );
		$this->assertStringContainsString( 'id="cpub-can-publish"', $html );
	}

	public function test_an_administrator_or_subscriber_cannot_be_chosen_even_by_a_forged_form() {
		foreach ( array( $this->admin, self::factory()->user->create( array( 'role' => 'subscriber' ) ), 999999, 'x' ) as $who ) {
			$page = $this->consent( $this->authorize_query(), 'approve', null, array( 'post_as' => (string) $who ) );
			$this->assertNull( $page['redirect'], "post_as=$who" );
			$this->assertStringContainsString( 'Authors or Editors', $page['html'] );
		}
		$this->assertTrue( PublisherUser::may_post_as( get_userdata( $this->editor ) ) );
		// An Editor someone gave administrator powers to (a plugin adding manage_options) can't be chosen.
		get_userdata( $this->editor )->add_cap( 'manage_options' );
		$this->assertFalse( PublisherUser::may_post_as( get_userdata( $this->editor ) ) );
	}

	public function test_posts_appear_under_the_chosen_person_and_are_marked() {
		$token = $this->token_as( $this->editor );
		$me    = $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token )->get_data();
		$this->assertSame( 'Sara Editor', $me['user']['name'] );
		$this->assertSame( 'editor', $me['user']['role'] );
		$this->assertFalse( $me['user']['separate_account'] );
		$this->assertFalse( $me['can_publish'] );

		$res = $this->create( $token, array( 'title' => 'Hello', 'status' => 'draft' ) );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$post = get_post( $res->get_data()['id'] );
		$this->assertSame( $this->editor, (int) $post->post_author );
		$this->assertTrue( Ownership::is_ours( $post->ID ) );
		$this->assertNull( PublisherUser::get(), 'no separate account was created' );
	}

	// ------------------------------------------------------------ limited to the agency's own work

	public function test_the_persons_own_posts_are_out_of_reach() {
		$own     = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_status' => 'draft', 'post_title' => 'Sara private draft' ) );
		$others  = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish', 'post_title' => 'Live by Omar' ) );
		$page    = self::factory()->post->create( array( 'post_author' => $this->editor, 'post_type' => 'page', 'post_status' => 'draft' ) );
		$token   = $this->token_as( $this->editor, true );

		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/posts/{$own}", array( 'title' => 'Hijacked' ), $token )->get_status() );
		$this->assertSame( 'Sara private draft', get_post( $own )->post_title );
		$this->assertNotSame( 200, $this->api( 'GET', "/wp/v2/posts/{$own}", array( 'context' => 'edit' ), $token )->get_status(), 'her draft is not readable' );
		$list = $this->api( 'GET', '/wp/v2/posts', array( 'status' => 'draft', 'context' => 'edit' ), $token );
		$this->assertNotContains( $own, array_column( (array) $list->get_data(), 'id' ), 'nor listed' );
		// An Editor may edit others' posts, but the token may not.
		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/posts/{$others}", array( 'status' => 'draft' ), $token )->get_status() );
		$this->assertSame( 'publish', get_post_status( $others ) );
		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/pages/{$page}", array( 'title' => 'x' ), $token )->get_status() );
		// Public posts stay readable, as for anyone.
		$this->assertSame( 200, $this->api( 'GET', "/wp/v2/posts/{$others}", array(), $token )->get_status() );
	}

	public function test_the_persons_other_powers_are_withheld() {
		$token = $this->token_as( $this->editor );
		$this->api( 'GET', '/wp/v2/users/me', array(), $token );
		$this->assertSame( $this->editor, get_current_user_id() );
		foreach ( array( 'unfiltered_html', 'edit_others_posts', 'publish_posts', 'manage_categories', 'moderate_comments', 'edit_pages', 'delete_others_posts' ) as $cap ) {
			$this->assertFalse( current_user_can( $cap ), $cap );
		}
		// Editors may post raw HTML; through the token it is filtered like an Author's.
		$res = $this->create( $token, array( 'title' => 'T', 'content' => '<p>ok</p><script>alert(1)</script><iframe src="https://x.test"></iframe>', 'status' => 'draft' ) );
		$this->assertSame( 201, $res->get_status() );
		$content = get_post( $res->get_data()['id'] )->post_content;
		$this->assertStringNotContainsString( '<script', $content );
		$this->assertStringNotContainsString( '<iframe', $content );
		// The same person signed in normally keeps everything.
		$this->use_token( null );
		wp_set_current_user( $this->editor );
		$this->assertTrue( current_user_can( 'unfiltered_html' ) || is_multisite() );
		$this->assertTrue( current_user_can( 'edit_others_posts' ) );
	}

	public function test_access_stops_if_the_person_is_promoted_demoted_or_deleted() {
		foreach ( array( 'administrator', 'subscriber', 'contributor' ) as $role ) {
			$user  = self::factory()->user->create( array( 'role' => 'author' ) );
			$token = $this->token_as( $user );
			get_userdata( $user )->set_role( $role );
			$res = $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token );
			$this->assertSame( 401, $res->get_status(), $role );
			$this->assertNull( Grants::active(), $role );
		}
		$user  = self::factory()->user->create( array( 'role' => 'author' ) );
		$token = $this->token_as( $user );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user );
		$this->assertSame( 401, $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token )->get_status() );
		$this->assertNull( Grants::active() );
	}

	// ------------------------------------------------------------ publishing

	public function test_without_the_switch_posts_stay_drafts() {
		$token = $this->token_as( $this->author );
		foreach ( array( 'publish', 'future', 'private' ) as $status ) {
			$this->assertSame( 403, $this->create( $token, array( 'title' => 'X', 'status' => $status, 'date' => '2099-01-01T00:00:00' ) )->get_status(), $status );
		}
		$id = $this->create( $token, array( 'title' => 'D', 'status' => 'draft' ) )->get_data()['id'];
		wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ); // the site's editors publish it
		$res = $this->api( 'POST', "/wp/v2/posts/{$id}", array( 'title' => 'Changed' ), $token );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'cpub_post_locked', $res->get_data()['code'] );
		// Still readable in full for the daily check.
		$this->assertSame( 'publish', $this->api( 'GET', "/wp/v2/posts/{$id}", array( 'context' => 'edit' ), $token )->get_data()['status'] );
	}

	public function test_with_the_switch_the_agency_publishes_schedules_corrects_and_unpublishes_its_own_posts() {
		$token = $this->token_as( $this->author, true );
		$this->assertTrue( $this->api( 'GET', '/cpub-connector/v1/connection', array(), $token )->get_data()['can_publish'] );

		$live = $this->create( $token, array( 'title' => 'Live', 'status' => 'publish' ) );
		$this->assertSame( 201, $live->get_status(), wp_json_encode( $live->get_data() ) );
		$id = $live->get_data()['id'];
		$this->assertSame( 'publish', get_post_status( $id ) );

		$later = $this->create( $token, array( 'title' => 'Later', 'status' => 'publish', 'date_gmt' => gmdate( 'Y-m-d\TH:i:s', time() + WEEK_IN_SECONDS ) ) );
		$this->assertSame( 'future', get_post_status( $later->get_data()['id'] ) );

		$this->assertSame( 403, $this->create( $token, array( 'title' => 'P', 'status' => 'private' ) )->get_status() );

		$this->assertSame( 200, $this->api( 'POST', "/wp/v2/posts/{$id}", array( 'title' => 'Live, corrected' ), $token )->get_status() );
		$this->assertSame( 'Live, corrected', get_post( $id )->post_title );
		$this->assertSame( 200, $this->api( 'POST', "/wp/v2/posts/{$id}", array( 'status' => 'draft' ), $token )->get_status() );
		$this->assertSame( 'draft', get_post_status( $id ) );

		// Never someone else's published post.
		$theirs = self::factory()->post->create( array( 'post_author' => $this->author, 'post_status' => 'publish' ) );
		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/posts/{$theirs}", array( 'status' => 'draft' ), $token )->get_status() );
		$this->assertSame( 'publish', get_post_status( $theirs ) );
	}

	public function test_the_site_can_switch_publishing_on_and_off_later() {
		$token = $this->token_as( $this->author );
		$grant = Grants::active();
		add_filter( 'cpub_connector_exit_after_redirect', '__return_false' );
		add_filter( 'wp_redirect', '__return_false' );

		wp_set_current_user( $this->editor ); // not an administrator
		$_POST = $_REQUEST = array( 'allow' => '1', '_wpnonce' => wp_create_nonce( ConnectionActions::PUBLISHING ) );
		try {
			ConnectionActions::publishing();
			$this->fail( 'an editor changed it' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( Grants::can_publish( Grants::get( (int) $grant->id ) ) );
		}

		wp_set_current_user( $this->admin );
		$_POST = $_REQUEST = array( 'allow' => '1', '_wpnonce' => wp_create_nonce( ConnectionActions::PUBLISHING ) );
		ConnectionActions::publishing();
		$this->assertTrue( Grants::can_publish( Grants::get( (int) $grant->id ) ) );
		$this->assertSame( 201, $this->create( $token, array( 'title' => 'Now live', 'status' => 'publish' ) )->get_status() );

		wp_set_current_user( $this->admin );
		$_POST = $_REQUEST = array( 'allow' => '0', '_wpnonce' => wp_create_nonce( ConnectionActions::PUBLISHING ) );
		ConnectionActions::publishing();
		$this->assertSame( 403, $this->create( $token, array( 'title' => 'Again', 'status' => 'publish' ) )->get_status() );
	}

	// ------------------------------------------------------------ tags

	public function test_new_tags_can_be_created_and_nothing_else() {
		$token = $this->token_as( $this->author, false, true );
		$res   = $this->api( 'POST', '/wp/v2/tags', array( 'name' => 'Pour-over' ), $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$tag = $res->get_data()['id'];
		$this->assertFalse( current_user_can( 'manage_categories' ), 'not left with the power to manage terms' );

		$this->assertSame( 403, $this->api( 'POST', "/wp/v2/tags/{$tag}", array( 'name' => 'Renamed' ), $token )->get_status() );
		$this->assertSame( 403, $this->api( 'DELETE', "/wp/v2/tags/{$tag}", array( 'force' => true ), $token )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', '/wp/v2/categories', array( 'name' => 'New cat' ), $token )->get_status() );
		$this->assertSame( 403, $this->api( 'POST', '/wp/v2/tags', array( 'name' => 'x', 'description' => '<b>x</b>' ), $token )->get_status(), 'only a name' );
		$this->assertSame( 'Pour-over', get_term( $tag )->name );
	}

	public function test_tags_cannot_be_created_without_that_permission() {
		$token = $this->token_as( $this->author );
		$this->assertSame( 403, $this->api( 'POST', '/wp/v2/tags', array( 'name' => 'Nope' ), $token )->get_status() );
		$this->assertEmpty( term_exists( 'Nope', 'post_tag' ) );
	}

	// ------------------------------------------------------------ upgrading from 0.4.0

	public function test_the_separate_account_still_works_and_its_old_posts_are_claimed() {
		$token = $this->connect()['access_token']; // no choice sent: the separate account, as before
		$agent = PublisherUser::get();
		$this->assertNotNull( $agent );
		$old = self::factory()->post->create( array( 'post_author' => $agent->ID, 'post_status' => 'draft' ) );
		$this->assertFalse( Ownership::is_ours( $old ) );
		$this->assertSame( 1, Ownership::mark_legacy( $agent->ID ) );
		$this->assertSame( 200, $this->api( 'POST', "/wp/v2/posts/{$old}", array( 'title' => 'Updated' ), $token )->get_status() );
		$this->assertSame( 0, Ownership::mark_legacy( $agent->ID ), 'marked once' );
	}
}
