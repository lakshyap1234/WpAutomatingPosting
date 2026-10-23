<?php

use CPub\Connector\ActivityLog;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\Rest\Preview;

/**
 * Signed preview links: GET /cpub-connector/v1/preview/{id} for the agency's own
 * unpublished post or page, and that link opening it through the theme, logged out.
 */
class PreviewTest extends OAuthTestCase {

	private function token( bool $can_publish = false, string $scope = self::SCOPES ): string {
		return $this->connect( $can_publish ? array( 'can_publish' => '1' ) : array(), array( 'scope' => $scope ) )['access_token'];
	}

	private function create( string $token, string $route = '/wp/v2/posts', array $params = array() ): int {
		$res = $this->api( 'POST', $route, $params + array( 'title' => 'Preview me', 'content' => 'Draft body', 'status' => 'draft' ), $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return (int) $res->get_data()['id'];
	}

	private function link( string $token, int $id ): WP_REST_Response {
		return $this->api( 'GET', "/cpub-connector/v1/preview/{$id}", array(), $token );
	}

	/** Opens a URL on the front end as a logged-out visitor, as a browser would. */
	private function visit( string $url ): void {
		$this->use_token( null );
		wp_set_current_user( 0 );
		$this->go_to( $url );
	}

	/** @return string|null the wp_die() message, or null if the page rendered */
	private function visit_refused( string $url ): ?string {
		try {
			$this->visit( $url );
		} catch ( WPDieException $e ) {
			return $e->getMessage();
		}
		return null;
	}

	// ------------------------------------------------------------ asking for a link

	public function test_a_link_for_an_own_draft(): void {
		$token = $this->token();
		$id    = $this->create( $token );
		$res   = $this->link( $token, $id );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( Preview::TTL, $data['expires_in'] );
		$this->assertEqualsWithDelta( time() + Preview::TTL, strtotime( $data['expires_at'] ), 5 );
		parse_str( (string) wp_parse_url( $data['url'], PHP_URL_QUERY ), $q );
		$this->assertSame( (string) $id, $q['p'] );
		$this->assertMatchesRegularExpression( '/^' . $id . '\.\d+\.\d+\.[a-f0-9]{64}$/', $q[ Preview::QUERY_VAR ] );
		$this->assertStringStartsWith( home_url( '/' ), $data['url'] );
	}

	public function test_refused_for_posts_the_agency_did_not_create(): void {
		$token  = $this->token();
		$theirs = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$res    = $this->link( $token, $theirs );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'cpub_preview_not_ours', $res->get_data()['code'] );
		$this->assertSame( 404, $this->link( $token, 999999 )->get_status() );
		$media = self::factory()->attachment->create();
		$this->assertSame( 404, $this->link( $token, $media )->get_status(), 'only posts and pages' );
	}

	public function test_published_and_binned_posts_get_no_link(): void {
		$token = $this->token( true );
		$live  = $this->create( $token, '/wp/v2/posts', array( 'status' => 'publish' ) );
		$res   = $this->link( $token, $live );
		$this->assertSame( 409, $res->get_status() );
		$this->assertSame( 'cpub_preview_not_needed', $res->get_data()['code'] );
		$this->assertSame( get_permalink( $live ), $res->get_data()['data']['link'] );

		$binned = $this->create( $token );
		wp_trash_post( $binned );
		$this->assertSame( 409, $this->link( $token, $binned )->get_status() );
	}

	public function test_needs_a_token_with_the_scope(): void {
		$token = $this->token();
		$id    = $this->create( $token );
		$this->use_token( null );
		wp_set_current_user( $this->admin );
		$this->assertNotSame( 200, $this->api( 'GET', "/cpub-connector/v1/preview/{$id}" )->get_status(), 'not for a logged-in user without a token' );

		$read_only = $this->token( false, 'account:read' );
		$res       = $this->link( $read_only, $id );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'cpub_forbidden', $res->get_data()['code'] );
	}

	public function test_a_page_needs_pages_write(): void {
		$token = $this->token( false, self::SCOPES . ' pages:write' );
		$page  = $this->create( $token, '/wp/v2/pages' );
		$res   = $this->link( $token, $page );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		parse_str( (string) wp_parse_url( $res->get_data()['url'], PHP_URL_QUERY ), $q );
		$this->assertSame( (string) $page, $q['page_id'] );

		// Reconnected without pages:write: the page is still the agency's, but not previewable.
		$posts_only = $this->token();
		$this->assertSame( 'cpub_preview_not_ours', $this->link( $posts_only, $page )->get_data()['code'] );
	}

	public function test_each_link_is_logged(): void {
		$token = $this->token();
		$id    = $this->create( $token );
		$this->link( $token, $id );
		$actions = wp_list_pluck( ActivityLog::recent( 10 ), 'action' );
		$this->assertContains( 'preview_link', $actions );
	}

	// ------------------------------------------------------------ opening it

	public function test_the_link_shows_the_draft_logged_out(): void {
		$token = $this->token();
		$id    = $this->create( $token );
		$url   = $this->link( $token, $id )->get_data()['url'];

		$this->visit( get_permalink( $id ) );
		$this->assertFalse( have_posts(), 'without the link, a logged-out visitor sees nothing' );

		$this->visit( $url );
		$this->assertTrue( is_single(), 'shown as a single post, through the theme\'s template' );
		$this->assertFalse( is_404() );
		$this->assertSame( $id, get_queried_object_id() );
		$this->assertSame( array( $id ), wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ) );
		$this->assertSame( 'draft', get_post_status( $id ), 'and stays a draft' );
		$this->assertNotFalse( has_filter( 'wp_robots', 'wp_robots_no_robots' ), 'noindex in the page' );
		$this->assertSame( 'noindex, nofollow', Preview::headers()['X-Robots-Tag'] );
		$this->assertSame( 'no-referrer', Preview::headers()['Referrer-Policy'] );
		$this->assertFalse( comments_open( $id ) );
	}

	public function test_a_page_and_a_scheduled_post_open_too(): void {
		$token  = $this->token( true, self::SCOPES . ' pages:write' );
		$page   = $this->create( $token, '/wp/v2/pages' );
		$future = $this->create( $token, '/wp/v2/posts', array( 'status' => 'future', 'date' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) ) );
		$this->assertSame( 'future', get_post_status( $future ) );

		$this->visit( $this->link( $token, $page )->get_data()['url'] );
		$this->assertTrue( is_page() );
		$this->assertSame( $page, get_queried_object_id() );

		$this->visit( $this->link( $token, $future )->get_data()['url'] );
		$this->assertSame( $future, get_queried_object_id() );
	}

	public function test_a_link_stops_working(): void {
		$token = $this->token( true );
		$id    = $this->create( $token );
		$other = $this->create( $token );
		$url   = $this->link( $token, $id )->get_data()['url'];
		$grant = (int) Grants::active()->id;
		$post  = get_post( $id );

		$cases = array(
			'expired'            => Preview::url( $post, $grant, time() - 1 ),
			'longer than 30 min' => Preview::url( $post, $grant, time() + 2 * Preview::TTL ),
			'tampered'           => substr( $url, 0, -1 ) . ( str_ends_with( $url, 'a' ) ? 'b' : 'a' ),
			'for another post'   => add_query_arg( 'p', $other, $url ),
			'without the post'   => remove_query_arg( 'p', $url ),
			'another connection' => Preview::url( $post, $grant + 1, time() + 60 ),
		);
		foreach ( $cases as $label => $bad ) {
			$this->assertStringContainsString( 'expired or is no longer valid', (string) $this->visit_refused( $bad ), $label );
		}
		$this->assertNull( $this->visit_refused( $url ), 'the real link still works' );

		// Once published, the draft link is done.
		wp_publish_post( $id );
		$this->assertNotNull( $this->visit_refused( $url ), 'published' );
	}

	public function test_disconnecting_ends_every_link(): void {
		$token = $this->token();
		$url   = $this->link( $token, $this->create( $token ) )->get_data()['url'];
		$this->assertNull( $this->visit_refused( $url ) );
		Grants::revoke_all( 'disconnected', $this->admin );
		$this->assertNotNull( $this->visit_refused( $url ) );
	}
}
