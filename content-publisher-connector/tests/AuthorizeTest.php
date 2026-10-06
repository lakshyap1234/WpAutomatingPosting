<?php

use CPub\Connector\OAuth\Grants;
use CPub\Connector\PublisherUser;

/** The consent screen. */
class AuthorizeTest extends OAuthTestCase {

	public function test_shows_agency_scopes_and_return_host(): void {
		$page = $this->consent( $this->authorize_query() );
		$this->assertNull( $page['redirect'] );
		$this->assertStringContainsString( 'Test Agency wants access', $page['html'] );
		foreach ( array( 'posts:write', 'media:write', 'terms:read', 'account:read' ) as $scope ) {
			$this->assertStringContainsString( 'data-scope="' . $scope . '"', $page['html'] );
		}
		$this->assertStringContainsString( 'agency.test', $page['html'] );
		$this->assertStringNotContainsString( 'cpub-loopback-warning', $page['html'] );
		$this->assertStringContainsString( 'id="cpub-approve"', $page['html'] );
	}

	public function test_non_admin_is_refused(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->expectException( WPDieException::class );
		$this->consent( $this->authorize_query(), null, $editor );
	}

	public function test_unknown_client_is_shown_an_error_and_not_redirected(): void {
		$page = $this->consent( $this->authorize_query( array( 'client_id' => 'evil' ) ) );
		$this->assertNull( $page['redirect'] );
		$this->assertStringContainsString( 'cpub-authorize-error', $page['html'] );
		$this->assertStringNotContainsString( 'cpub-approve', $page['html'] );
	}

	/** @dataProvider foreign_redirects */
	public function test_unregistered_return_address_is_never_redirected_to( string $uri ): void {
		$page = $this->consent( $this->authorize_query( array( 'redirect_uri' => $uri ) ) );
		$this->assertNull( $page['redirect'], 'Must not send the browser to ' . $uri );
		$this->assertStringContainsString( 'cpub-authorize-error', $page['html'] );
	}

	public static function foreign_redirects(): array {
		return array(
			'other host'      => array( 'https://evil.test/wp-admin/admin.php?page=cpub-publisher&cpub_oauth=callback' ),
			'extra param'     => array( OAuthTestCase::REDIRECT . '&x=1' ),
			'http downgrade'  => array( str_replace( 'https://', 'http://', OAuthTestCase::REDIRECT ) ),
			'other loopback path' => array( 'http://127.0.0.1/other' ),
		);
	}

	public function test_missing_pkce_is_sent_back_as_an_error(): void {
		$query = $this->authorize_query();
		unset( $query['code_challenge'], $query['code_challenge_method'] );
		$page = $this->consent( $query );
		$this->assertStringStartsWith( self::REDIRECT, (string) $page['redirect'] );
		$this->assertSame( 'invalid_request', self::query_of( $page['redirect'] )['error'] );
	}

	public function test_plain_pkce_is_refused(): void {
		$page = $this->consent( $this->authorize_query( array( 'code_challenge_method' => 'plain', 'code_challenge' => str_repeat( 'a', 50 ) ) ) );
		$q    = self::query_of( (string) $page['redirect'] );
		$this->assertSame( 'invalid_request', $q['error'] );
		$this->assertStringStartsWith( 'state-', $q['state'] );
	}

	public function test_unknown_scope_is_refused(): void {
		$page = $this->consent( $this->authorize_query( array( 'scope' => 'posts:write site:admin' ) ) );
		$this->assertSame( 'invalid_scope', self::query_of( (string) $page['redirect'] )['error'] );
	}

	public function test_approve_redirects_with_code_and_state_and_creates_author_user(): void {
		$query = $this->authorize_query();
		$page  = $this->consent( $query, 'approve' );
		$q     = self::query_of( (string) $page['redirect'] );
		$this->assertStringStartsWith( self::REDIRECT . '&code=', $page['redirect'] );
		$this->assertSame( $query['state'], $q['state'] );
		$this->assertNotEmpty( $q['code'] );

		$user = PublisherUser::get();
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( array( 'author' ), array_values( $user->roles ) );
		$this->assertSame( 'Test Agency', $user->display_name );
		$this->assertFalse( PublisherUser::too_powerful( $user ) );
		$this->assertNull( Grants::active(), 'Not connected until the code is exchanged.' );
	}

	public function test_second_approval_reuses_the_user(): void {
		$this->consent( $this->authorize_query(), 'approve' );
		$first = PublisherUser::get()->ID;
		$this->consent( $this->authorize_query(), 'approve' );
		$this->assertSame( $first, PublisherUser::get()->ID );
	}

	public function test_reinstall_reuses_the_existing_user(): void {
		$this->consent( $this->authorize_query(), 'approve' );
		$first = PublisherUser::get()->ID;
		delete_option( PublisherUser::OPTION ); // what uninstall does
		$this->consent( $this->authorize_query(), 'approve' );
		$this->assertSame( $first, PublisherUser::get()->ID );
		$this->assertCount( 1, get_users( array( 'meta_key' => PublisherUser::META ) ) );
	}

	public function test_approval_resets_a_changed_role(): void {
		$this->consent( $this->authorize_query(), 'approve' );
		PublisherUser::get()->set_role( 'editor' );
		$this->consent( $this->authorize_query(), 'approve' );
		$this->assertSame( array( 'author' ), array_values( PublisherUser::get()->roles ) );
	}

	public function test_deny_redirects_with_access_denied(): void {
		$query = $this->authorize_query();
		$page  = $this->consent( $query, 'deny' );
		$q     = self::query_of( (string) $page['redirect'] );
		$this->assertSame( 'access_denied', $q['error'] );
		$this->assertSame( $query['state'], $q['state'] );
		$this->assertArrayNotHasKey( 'code', $q );
	}

	public function test_post_without_valid_nonce_is_refused(): void {
		wp_set_current_user( $this->admin );
		$_GET                      = $this->authorize_query();
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $_REQUEST = array( 'approve' => '1', '_wpnonce' => 'forged' ); // phpcs:ignore
		$this->expectException( WPDieException::class );
		( new \CPub\Connector\Admin\AuthorizePage() )->load();
	}

	public function test_loopback_return_address_shows_a_warning(): void {
		$page = $this->consent( $this->authorize_query( array( 'redirect_uri' => 'http://127.0.0.1:8765/callback' ) ) );
		$this->assertStringContainsString( 'cpub-loopback-warning', $page['html'] );
	}

	public function test_publisher_user_cannot_log_in_or_get_app_passwords(): void {
		$this->consent( $this->authorize_query(), 'approve' );
		$user = PublisherUser::get();
		$this->assertWPError( wp_authenticate( $user->user_login, 'anything' ) );
		wp_set_password( 'known-pass-123', $user->ID );
		$this->assertWPError( wp_authenticate( $user->user_login, 'known-pass-123' ), 'Even with the right password.' );
		$this->assertFalse( wp_is_application_passwords_available_for_user( $user ) );
	}
}
