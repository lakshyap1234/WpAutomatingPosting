<?php

use CPub\Connector\ActivityLog;
use CPub\Connector\Admin\ProposalsPage;
use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\Proposals;

/**
 * proposals:write: the agency suggests changes to the site's published posts and
 * pages; the site's editors apply or reject them. The token never changes a post.
 */
class ProposalsTest extends OAuthTestCase {

	private int $editor;
	private int $live;

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->live   = self::factory()->post->create(
			array(
				'post_author'  => $this->editor,
				'post_status'  => 'publish',
				'post_title'   => 'Our services',
				'post_content' => "<!-- wp:paragraph -->\n<p>We fix roofs.</p>\n<!-- /wp:paragraph -->",
				'post_excerpt' => 'Roofs.',
			)
		);
	}

	private function token( bool $can_publish = true ): string {
		$fields = array( 'post_as' => (string) $this->editor ) + ( $can_publish ? array( 'can_publish' => '1' ) : array() );
		return $this->connect( $fields, array( 'scope' => self::SCOPES . ' proposals:write' ) )['access_token'];
	}

	private function propose( string $token, array $params ): WP_REST_Response {
		return $this->api( 'POST', '/cpub-connector/v1/proposals', $params + array( 'post' => $this->live ), $token );
	}

	private function proposal_id( string $token, array $params = array() ): int {
		$res = $this->propose( $token, $params + array( 'title' => 'Roof repairs', 'content' => "<!-- wp:paragraph -->\n<p>We fix and replace roofs.</p>\n<!-- /wp:paragraph -->" ) );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return (int) $res->get_data()['id'];
	}

	/** The original as it is now, to compare before and after. */
	private function snapshot( int $id ): array {
		clean_post_cache( $id );
		$p = get_post( $id );
		return array( $p->post_title, $p->post_content, $p->post_excerpt, $p->post_status, $p->post_modified_gmt, count( wp_get_post_revisions( $id ) ) );
	}

	/** The Apply / Reject form, as $user_id. */
	private function decide( int $proposal, int $user_id, string $button, string $note = '' ): ?string {
		$this->use_token( null );
		wp_set_current_user( $user_id );
		$_POST    = array( 'proposal' => (string) $proposal, $button => '1', 'note' => $note, '_wpnonce' => wp_create_nonce( ProposalsPage::ACTION . '-' . $proposal ) );
		$_REQUEST = $_POST;
		add_filter( 'cpub_connector_exit_after_redirect', '__return_false' );
		$this->redirected = null;
		ProposalsPage::decide();
		return $this->redirected;
	}

	private function state( string $token, int $proposal ): array {
		$res = $this->api( 'GET', "/cpub-connector/v1/proposals/{$proposal}", array(), $token );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		return $res->get_data();
	}

	// ------------------------------------------------------------ the scope

	public function test_refused_without_the_scope(): void {
		$token = $this->connect( array( 'post_as' => (string) $this->editor ) )['access_token'];
		foreach ( array( array( 'POST', '/cpub-connector/v1/proposals' ), array( 'GET', '/cpub-connector/v1/proposals' ), array( 'GET', "/cpub-connector/v1/proposals/source/{$this->live}" ) ) as [ $method, $route ] ) {
			$res = $this->api( $method, $route, array( 'post' => $this->live, 'title' => 'x' ), $token );
			$this->assertSame( 403, $res->get_status(), "$method $route" );
			$this->assertSame( 'cpub_forbidden', $res->get_data()['code'], "$method $route" );
		}
	}

	public function test_the_consent_screen_lists_it(): void {
		$html = $this->consent( $this->authorize_query( array( 'scope' => self::SCOPES . ' proposals:write' ) ) )['html'];
		$this->assertStringContainsString( 'data-scope="proposals:write"', $html );
	}

	// ------------------------------------------------------------ the token never changes the original

	public function test_the_token_can_never_modify_the_original(): void {
		$token  = $this->token();
		$before = $this->snapshot( $this->live );

		$id = $this->proposal_id( $token, array( 'excerpt' => 'Roofs, gutters.' ) );
		$this->assertSame( $before, $this->snapshot( $this->live ), 'suggesting changes nothing' );

		// Every other way in, with a token that can publish and whose user is an Editor.
		$attempts = array(
			array( 'POST', "/wp/v2/posts/{$this->live}", array( 'title' => 'Hijacked' ) ),
			array( 'PUT', "/wp/v2/posts/{$this->live}", array( 'content' => 'Hijacked' ) ),
			array( 'DELETE', "/wp/v2/posts/{$this->live}", array() ),
			array( 'POST', "/cpub-connector/v1/proposals/{$id}", array( 'state' => 'applied' ) ),
			array( 'PUT', "/cpub-connector/v1/proposals/{$id}", array( 'state' => 'applied' ) ),
			array( 'POST', '/cpub-connector/v1/proposals', array( 'post' => $this->live, 'title' => 'T', 'status' => 'draft' ) ),
			array( 'POST', '/cpub-connector/v1/proposals', array( 'post' => $this->live, 'title' => 'T', 'meta' => array( 'x' => 1 ) ) ),
			array( 'POST', '/batch/v1', array( 'requests' => array( array( 'method' => 'POST', 'path' => "/wp/v2/posts/{$this->live}", 'body' => array( 'title' => 'Hijacked' ) ) ) ) ),
		);
		foreach ( $attempts as [ $method, $route, $params ] ) {
			$res = $this->api( $method, $route, $params, $token );
			$this->assertGreaterThanOrEqual( 400, $res->get_status(), "$method $route" );
		}
		$this->assertSame( $before, $this->snapshot( $this->live ), 'nothing reached the post' );
		$this->assertSame( Proposals::PENDING, Proposals::get( $id )->state, 'nor decided the suggestion' );

		// Even code running during a token request can't apply it.
		$this->use_token( $token );
		apply_filters( 'rest_authentication_errors', null );
		$this->assertNotNull( BearerAuth::grant() );
		$result = Proposals::apply( Proposals::get( $id ), $this->editor );
		$this->assertWPError( $result );
		$this->assertSame( 'cpub_proposal_token', $result->get_error_code() );
		$this->assertSame( $before, $this->snapshot( $this->live ) );
	}

	// ------------------------------------------------------------ suggesting

	public function test_a_suggestion_is_stored_cleaned(): void {
		$token = $this->token();
		$res   = $this->propose(
			$token,
			array(
				'title'   => '<b>Roof</b> repairs',
				'content' => "<!-- wp:paragraph -->\n<p onclick=\"steal()\">New text</p>\n<!-- /wp:paragraph --><script>steal()</script>",
			)
		);
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$data = $res->get_data();
		$this->assertSame( Proposals::PENDING, $data['state'] );
		$this->assertSame( array( 'title', 'content' ), $data['fields'] );
		$this->assertSame( 'Roof repairs', $data['proposed']['title'] );
		$this->assertNull( $data['proposed']['excerpt'], 'left as it is' );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $data['proposed']['content'], 'block markup kept' );
		$this->assertStringNotContainsString( 'script', $data['proposed']['content'] );
		$this->assertStringNotContainsString( 'onclick', $data['proposed']['content'] );
		$this->assertContains( 'proposal_received', wp_list_pluck( ActivityLog::recent( 10 ), 'action' ) );
	}

	/** @dataProvider not_targets */
	public function test_only_published_posts_and_pages_can_get_suggestions( array $post ): void {
		$token = $this->token();
		$id    = self::factory()->post->create( $post + array( 'post_author' => $this->editor ) );
		$res   = $this->propose( $token, array( 'post' => $id, 'title' => 'T' ) );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 'cpub_proposal_target', $res->get_data()['code'] );
		$this->assertSame( 404, $this->api( 'GET', "/cpub-connector/v1/proposals/source/{$id}", array(), $token )->get_status(), 'nor its text read' );
	}

	public static function not_targets(): array {
		return array(
			'draft'              => array( array( 'post_status' => 'draft' ) ),
			'pending'            => array( array( 'post_status' => 'pending' ) ),
			'private'            => array( array( 'post_status' => 'private' ) ),
			'scheduled'          => array( array( 'post_status' => 'future', 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) ),
			'password protected' => array( array( 'post_status' => 'publish', 'post_password' => 'secret' ) ),
			'another post type'  => array( array( 'post_status' => 'publish', 'post_type' => 'wp_block' ) ),
		);
	}

	public function test_a_suggestion_needs_a_change(): void {
		$token = $this->token();
		foreach ( array( 'nothing' => array(), 'empty title' => array( 'title' => '  ' ), 'empty content' => array( 'content' => '' ) ) as $label => $params ) {
			$res = $this->propose( $token, $params );
			$this->assertSame( 400, $res->get_status(), $label );
			$this->assertSame( 'cpub_proposal_invalid', $res->get_data()['code'], $label );
		}
	}

	public function test_a_published_page_works_too(): void {
		$token = $this->token();
		$page  = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $this->editor ) );
		$this->assertSame( 201, $this->propose( $token, array( 'post' => $page, 'title' => 'About us' ) )->get_status() );
	}

	public function test_the_source_is_the_raw_text(): void {
		$token = $this->token();
		$res   = $this->api( 'GET', "/cpub-connector/v1/proposals/source/{$this->live}", array(), $token );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'Our services', $data['title'] );
		$this->assertSame( get_post( $this->live )->post_content, $data['content'] );
		$this->assertSame( 'Roofs.', $data['excerpt'] );
		$this->assertSame( 'post', $data['type'] );
	}

	public function test_a_newer_suggestion_replaces_the_waiting_one(): void {
		$token  = $this->token();
		$first  = $this->proposal_id( $token );
		$second = $this->proposal_id( $token, array( 'title' => 'Roofing' ) );
		$this->assertSame( Proposals::SUPERSEDED, $this->state( $token, $first )['state'] );
		$this->assertSame( Proposals::PENDING, $this->state( $token, $second )['state'] );
		$list = $this->api( 'GET', '/cpub-connector/v1/proposals', array( 'state' => 'pending' ), $token )->get_data();
		$this->assertSame( array( $second ), array_column( $list, 'id' ) );
	}

	// ------------------------------------------------------------ deciding

	public function test_an_editor_applies_it_and_the_agency_sees_applied(): void {
		$token = $this->token();
		$id    = $this->proposal_id( $token );
		$this->assertSame( Proposals::PENDING, $this->state( $token, $id )['state'] );

		$redirect = $this->decide( $id, $this->editor, 'apply' );
		$this->assertStringContainsString( 'cpub_done=applied', (string) $redirect );
		clean_post_cache( $this->live );
		$post = get_post( $this->live );
		$this->assertSame( 'Roof repairs', $post->post_title );
		$this->assertStringContainsString( 'We fix and replace roofs.', $post->post_content );
		$this->assertSame( 'Roofs.', $post->post_excerpt, 'a field not in the suggestion is left alone' );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( $this->editor, (int) $post->post_author );
		$this->assertNotEmpty( wp_get_post_revisions( $this->live ), 'the previous version is kept as a revision' );

		$data = $this->state( $token, $id );
		$this->assertSame( Proposals::APPLIED, $data['state'] );
		$this->assertNotNull( $data['decided_at'] );
		$this->assertSame( $this->editor, (int) Proposals::get( $id )->decided_by );
		$this->assertContains( 'proposal_applied', wp_list_pluck( ActivityLog::recent( 10 ), 'action' ) );

		// Only once.
		$this->assertStringContainsString( 'cpub_error=cpub_proposal_decided', (string) $this->decide( $id, $this->editor, 'apply' ) );
	}

	public function test_an_editor_rejects_it_with_a_reason(): void {
		$token  = $this->token();
		$id     = $this->proposal_id( $token );
		$before = $this->snapshot( $this->live );
		$this->decide( $id, $this->editor, 'reject', 'We only do repairs.' );
		$this->assertSame( $before, $this->snapshot( $this->live ) );
		$data = $this->state( $token, $id );
		$this->assertSame( Proposals::REJECTED, $data['state'] );
		$this->assertSame( 'We only do repairs.', $data['note'] );
		$this->assertContains( 'proposal_rejected', wp_list_pluck( ActivityLog::recent( 10 ), 'action' ) );
	}

	public function test_someone_who_can_not_edit_the_post_can_not_decide(): void {
		$token  = $this->token();
		$id     = $this->proposal_id( $token );
		$before = $this->snapshot( $this->live );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		try {
			$this->decide( $id, $author, 'apply' );
			$this->fail( 'An Author can\'t apply changes to someone else\'s post.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'can\'t review', $e->getMessage() );
		}
		$this->assertSame( $before, $this->snapshot( $this->live ) );
		$this->assertSame( Proposals::PENDING, Proposals::get( $id )->state );
	}

	public function test_a_post_that_is_no_longer_published_can_only_be_rejected(): void {
		$token = $this->token();
		$id    = $this->proposal_id( $token );
		wp_update_post( array( 'ID' => $this->live, 'post_status' => 'draft' ) );
		$this->assertStringContainsString( 'cpub_error=cpub_proposal_gone', (string) $this->decide( $id, $this->editor, 'apply' ) );
		$this->assertSame( 'Our services', get_post( $this->live )->post_title );
		$this->decide( $id, $this->editor, 'reject' );
		$this->assertSame( Proposals::REJECTED, Proposals::get( $id )->state );
	}

	// ------------------------------------------------------------ the screen

	public function test_the_review_screen_shows_a_comparison(): void {
		$token = $this->token();
		$id    = $this->proposal_id( $token );
		$this->use_token( null );
		wp_set_current_user( $this->editor );
		$_GET = array( 'page' => ProposalsPage::SLUG, 'proposal' => (string) $id );
		ob_start();
		( new ProposalsPage() )->render();
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/<table class=["\']diff/', $html );
		$this->assertStringContainsString( 'data-field="title"', $html );
		$this->assertStringContainsString( 'data-field="content"', $html );
		$this->assertStringContainsString( 'id="cpub-proposal-apply"', $html );
		$this->assertStringNotContainsString( 'id="cpub-proposal-stale"', $html );

		// The post was edited after the suggestion: say so.
		wp_update_post( array( 'ID' => $this->live, 'post_title' => 'Our services (2026)' ) );
		ob_start();
		( new ProposalsPage() )->render();
		$this->assertStringContainsString( 'id="cpub-proposal-stale"', ob_get_clean() );

		$_GET = array( 'page' => ProposalsPage::SLUG );
		ob_start();
		( new ProposalsPage() )->render();
		$this->assertStringContainsString( 'data-proposal="' . $id . '"', ob_get_clean() );
	}
}
