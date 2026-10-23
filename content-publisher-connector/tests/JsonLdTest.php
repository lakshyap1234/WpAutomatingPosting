<?php

use CPub\Connector\Admin\ProposalsPage;
use CPub\Connector\JsonLd;
use CPub\Connector\Proposals;

/**
 * cpub_jsonld: structured data on the agency's posts and pages and in proposals,
 * checked before it is saved and printed in the head so it can't break out.
 */
class JsonLdTest extends OAuthTestCase {

	private const FAQ = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array(
			array(
				'@type'          => 'Question',
				'name'           => 'Do you fix roofs?',
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Yes, all kinds.' ),
			),
		),
	);

	private function token( bool $can_publish = false, string $scope = self::SCOPES ): string {
		return $this->connect( $can_publish ? array( 'can_publish' => '1' ) : array(), array( 'scope' => $scope ) )['access_token'];
	}

	private function create( string $token, array $params = array(), string $route = '/wp/v2/posts' ): WP_REST_Response {
		return $this->api( 'POST', $route, $params + array( 'title' => 'Roofs', 'status' => 'draft' ), $token );
	}

	private static function post_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post','page')" );
	}

	/** The page head as a logged-out visitor sees it. */
	private function head_of( int $post_id ): string {
		$this->use_token( null );
		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $post_id ) );
		ob_start();
		JsonLd::print_head();
		return ob_get_clean();
	}

	/** The data in the printed <script type="application/ld+json"> tag. */
	private static function json_in( string $head ) {
		preg_match( '#^<script type="application/ld\+json">(.*)</script>\n$#s', $head, $m );
		return json_decode( $m[1] ?? '', true );
	}

	// ------------------------------------------------------------ on posts and pages

	public function test_set_with_a_post_and_printed_in_its_head(): void {
		$token = $this->token( true );
		$res   = $this->create( $token, array( 'status' => 'publish', JsonLd::FIELD => self::FAQ ) );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$id = (int) $res->get_data()['id'];
		$this->assertSame( self::FAQ, JsonLd::get( $id ) );
		$this->assertSame( self::FAQ, $this->api( 'GET', "/wp/v2/posts/{$id}", array( 'context' => 'edit' ), $token )->get_data()[ JsonLd::FIELD ] );

		$head = $this->head_of( $id );
		$this->assertStringStartsWith( '<script type="application/ld+json">', $head );
		$this->assertSame( self::FAQ, self::json_in( $head ) );
	}

	public function test_a_json_string_works_too_and_null_removes_it(): void {
		$token = $this->token();
		$id    = (int) $this->create( $token, array( JsonLd::FIELD => wp_json_encode( self::FAQ ) ) )->get_data()['id'];
		$this->assertSame( self::FAQ, JsonLd::get( $id ) );
		$this->assertSame( 200, $this->api( 'POST', "/wp/v2/posts/{$id}", array( JsonLd::FIELD => null ), $token )->get_status() );
		$this->assertNull( JsonLd::get( $id ) );
		$this->assertSame( '', get_post_meta( $id, JsonLd::META_KEY, true ) );
	}

	public function test_not_shown_outside_the_edit_context(): void {
		$token = $this->token( true );
		$id    = (int) $this->create( $token, array( 'status' => 'publish', JsonLd::FIELD => self::FAQ ) )->get_data()['id'];
		$this->use_token( null );
		wp_set_current_user( 0 );
		$this->assertArrayNotHasKey( JsonLd::FIELD, $this->api( 'GET', "/wp/v2/posts/{$id}" )->get_data() );
	}

	public function test_a_page_with_pages_write(): void {
		$token = $this->token( false, self::SCOPES . ' pages:write' );
		$res   = $this->create( $token, array( JsonLd::FIELD => array( '@context' => 'https://schema.org', '@type' => 'Service', 'name' => 'Roof repair' ) ), '/wp/v2/pages' );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'Service', JsonLd::get( (int) $res->get_data()['id'] )['@type'] );
	}

	public function test_only_on_the_agencys_own_posts(): void {
		$token  = $this->token( true );
		$theirs = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$res    = $this->api( 'POST', "/wp/v2/posts/{$theirs}", array( JsonLd::FIELD => self::FAQ ), $token );
		$this->assertSame( 403, $res->get_status() );
		$this->assertNull( JsonLd::get( $theirs ) );
	}

	public function test_the_meta_itself_is_out_of_reach(): void {
		$token = $this->token();
		$res   = $this->create( $token, array( 'meta' => array( JsonLd::META_KEY => '{"@context":"https://schema.org","@type":"Article"}' ) ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'cpub_field_not_allowed', $res->get_data()['code'] );
	}

	// ------------------------------------------------------------ what the issue asks for

	public function test_a_script_breakout_attempt_stays_inside_the_tag(): void {
		$token = $this->token( true );
		$evil  = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Article',
			'headline' => '</script><script>alert(document.cookie)</script>',
			'author'   => array( '@type' => 'Person', 'name' => '<!--<script>' ),
		);
		$res = $this->create( $token, array( 'status' => 'publish', JsonLd::FIELD => $evil ) );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$head = $this->head_of( (int) $res->get_data()['id'] );

		$this->assertSame( 1, substr_count( strtolower( $head ), '<script' ), 'one script tag: ours' );
		$this->assertSame( 1, substr_count( strtolower( $head ), '</script' ), 'closed once: by us' );
		$this->assertStringNotContainsString( '<!--', $head );
		$this->assertStringContainsString( '</script>', $head );
		$this->assertSame( $evil, self::json_in( $head ), 'and it still reads back exactly as sent' );
	}

	/** @dataProvider invalid */
	public function test_invalid_json_ld_is_refused_before_anything_is_saved( $value, string $why ): void {
		$token  = $this->token();
		$before = self::post_count();
		$res    = $this->create( $token, array( JsonLd::FIELD => $value ) );
		$this->assertSame( 400, $res->get_status(), $why . ': ' . wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $res->get_data()['code'] );
		$this->assertStringContainsString( $why, wp_json_encode( $res->get_data() ) );
		$this->assertSame( $before, self::post_count(), 'no post was created' );
	}

	public static function invalid(): array {
		$faq = self::FAQ;
		return array(
			'not JSON'             => array( '{"@context": "https://schema.org", "@type": "FAQPage",', 'not valid JSON' ),
			'not an object'        => array( '"just a string"', 'must be a JSON-LD object' ),
			'invalid type'         => array( array( '@type' => 'WebSite' ) + $faq, 'isn\'t allowed' ),
			'missing type'         => array( array( '@context' => 'https://schema.org', 'name' => 'x' ), 'isn\'t allowed' ),
			'type is not a name'   => array( array( '@type' => array( array( 'x' ) ) ) + $faq, 'schema.org type name' ),
			'wrong context'        => array( array( '@context' => 'https://evil.example/ctx' ) + $faq, '@context' ),
			'no context'           => array( array( '@type' => 'Article', 'headline' => 'x' ), '@context' ),
			'nested context'       => array( array( '@context' => 'https://schema.org', '@type' => 'Article', 'author' => array( '@context' => 'https://evil.example', '@type' => 'Person' ) ), 'only at the top' ),
			'oversize'             => array( array( '@context' => 'https://schema.org', '@type' => 'Article', 'articleBody' => str_repeat( 'a', JsonLd::MAX_BYTES ) ), 'larger than 32 KB' ),
			'oversize as a string' => array( '{"@context":"https://schema.org","@type":"Article","articleBody":"' . str_repeat( 'a', JsonLd::MAX_BYTES ) . '"}', 'larger than 32 KB' ),
			'too deep'             => array( array( '@context' => 'https://schema.org', '@type' => 'Article', 'x' => self::nest( JsonLd::MAX_DEPTH + 2 ) ), 'nested too deeply' ),
		);
	}

	private static function nest( int $depth ): array {
		return $depth > 0 ? array( 'a' => self::nest( $depth - 1 ) ) : array( 'end' => true );
	}

	public function test_lists_and_graphs_are_fine(): void {
		$crumbs = array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home' ) ) );
		$this->assertIsArray( JsonLd::validate( array( self::FAQ, $crumbs ) ) );
		$this->assertIsArray( JsonLd::validate( array( '@context' => 'https://schema.org', '@graph' => array( array( '@type' => 'Organization', 'name' => 'Roofs Ltd' ), array( '@type' => 'WebPage' ) ) ) ) );
		$this->assertWPError( JsonLd::validate( array( '@context' => 'https://schema.org', '@graph' => array( array( '@type' => 'WebSite' ) ) ) ), 'a graph item\'s type is checked too' );
	}

	public function test_stored_data_is_checked_again_before_printing(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, JsonLd::META_KEY, '{"@context":"https://evil.example","@type":"Article"}' ); // written some other way
		$this->assertNull( JsonLd::get( $id ) );
		$this->assertSame( '', $this->head_of( $id ) );
	}

	// ------------------------------------------------------------ in proposals

	public function test_a_proposal_sets_or_removes_it_when_applied(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$live   = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $editor, 'post_title' => 'Our services' ) );
		$token  = $this->connect( array( 'post_as' => (string) $editor ), array( 'scope' => self::SCOPES . ' proposals:write' ) )['access_token'];

		$bad = $this->api( 'POST', '/cpub-connector/v1/proposals', array( 'post' => $live, 'jsonld' => array( '@type' => 'WebSite' ) + self::FAQ ), $token );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'cpub_jsonld_invalid', $bad->get_data()['code'] );

		$res = $this->api( 'POST', '/cpub-connector/v1/proposals', array( 'post' => $live, 'jsonld' => self::FAQ ), $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( array( 'jsonld' ), $res->get_data()['fields'] );
		$this->assertSame( self::FAQ, $res->get_data()['proposed']['jsonld'] );
		$this->assertNull( JsonLd::get( $live ), 'nothing changes until an editor applies it' );

		$this->apply_as( $editor, (int) $res->get_data()['id'] );
		$this->assertSame( self::FAQ, JsonLd::get( $live ) );
		$this->assertSame( 'Our services', get_post( $live )->post_title, 'the rest is left alone' );
		$this->assertStringContainsString( 'FAQPage', $this->head_of( $live ) );

		$remove = $this->api( 'POST', '/cpub-connector/v1/proposals', array( 'post' => $live, 'jsonld' => '' ), $token );
		$this->assertSame( 201, $remove->get_status(), wp_json_encode( $remove->get_data() ) );
		$this->apply_as( $editor, (int) $remove->get_data()['id'] );
		$this->assertNull( JsonLd::get( $live ) );
	}

	private function apply_as( int $user_id, int $proposal ): void {
		$this->use_token( null );
		wp_set_current_user( $user_id );
		$_POST    = array( 'proposal' => (string) $proposal, 'apply' => '1', '_wpnonce' => wp_create_nonce( ProposalsPage::ACTION . '-' . $proposal ) );
		$_REQUEST = $_POST;
		ProposalsPage::decide();
		$this->assertSame( Proposals::APPLIED, Proposals::get( $proposal )->state, (string) $this->redirected );
	}
}
