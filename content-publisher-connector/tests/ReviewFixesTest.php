<?php

use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\Keys;
use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Vendor\League\OAuth2\Server\CryptTrait;

/** Regression tests for the findings of the independent security review. */
class ReviewFixesTest extends OAuthTestCase {

	public function test_intercepted_code_with_wrong_verifier_does_not_burn_it(): void {
		[ $code, $verifier ] = $this->approve();
		$this->assertSame( 400, $this->exchange( $code, str_repeat( 'x', 64 ) )->get_status() );
		$this->assertSame( 200, $this->exchange( $code, $verifier )->get_status(), 'The real agency can still complete the connection.' );
		$this->assertNotNull( Grants::active() );
	}

	public function test_token_endpoint_requires_s256_codes(): void {
		[ $code ] = $this->approve();
		// Re-seal a real, unused code with a "plain" challenge, as if the consent
		// screen's S256 check had been bypassed. Only the token endpoint's own check stops it.
		$crypt = new class() {
			use CryptTrait;
			public function __construct() {
				$this->setEncryptionKey( Keys::encryption_key() );
			}
			public function open( string $c ): array {
				return json_decode( $this->decrypt( $c ), true );
			}
			public function seal( array $p ): string {
				return $this->encrypt( wp_json_encode( $p ) );
			}
		};
		$payload                          = $crypt->open( $code );
		$payload['code_challenge']        = str_repeat( 'p', 50 );
		$payload['code_challenge_method'] = 'plain';
		$res = $this->exchange( $crypt->seal( $payload ), str_repeat( 'p', 50 ) );
		$this->assertSame( 400, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'invalid_grant', $res->get_data()['error'] );
		$this->assertNull( Grants::active() );
	}

	public function test_approvals_exchanged_out_of_order_leave_only_the_newest(): void {
		[ $code1, $v1 ] = $this->approve();
		[ $code2, $v2 ] = $this->approve();
		$new = $this->exchange( $code2, $v2 )->get_data();
		$old = $this->exchange( $code1, $v1 )->get_data();
		$this->assertSame( 200, $this->api( 'GET', '/wp/v2/users/me', array(), $new['access_token'] )->get_status() );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $old['access_token'] )->get_status() );
	}

	public function test_revoking_with_an_already_used_refresh_token_does_nothing(): void {
		$first = $this->connect();
		$this->refresh( $first['refresh_token'] );
		$request = new WP_REST_Request( 'POST', '/cpub-connector/v1/oauth/revoke' );
		$request->set_body_params( array( 'client_id' => self::CLIENT, 'token' => $first['refresh_token'] ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertNotNull( Grants::active(), 'An old token from a log must not disconnect the site.' );
	}

	public function test_revoking_with_an_expired_access_token_does_nothing(): void {
		global $wpdb;
		$tokens = $this->connect();
		$wpdb->query( 'UPDATE ' . \CPub\Connector\Installer::table( 'tokens' ) . " SET expires_at = '2000-01-01 00:00:00' WHERE kind = 'access'" );
		$request = new WP_REST_Request( 'POST', '/cpub-connector/v1/oauth/revoke' );
		$request->set_body_params( array( 'client_id' => self::CLIENT, 'token' => $tokens['access_token'] ) );
		rest_do_request( $request );
		$this->assertNotNull( Grants::active() );
	}

	/** @dataProvider forbidden_fields */
	public function test_post_fields_outside_the_allowlist_are_refused( string $field, $value ): void {
		$token = $this->connect()['access_token'];
		$res   = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'X', $field => $value ), $token );
		$this->assertSame( 403, $res->get_status(), $field );
		$this->assertSame( 'cpub_field_not_allowed', $res->get_data()['code'] );
	}

	public static function forbidden_fields(): array {
		return array(
			'sticky'         => array( 'sticky', true ),
			'meta'           => array( 'meta', array( 'some_seo_redirect' => 'https://evil.test' ) ),
			'template'       => array( 'template', 'full-width' ),
			'password'       => array( 'password', 'x' ),
			'author'         => array( 'author', 1 ),
			'comment_status' => array( 'comment_status', 'open' ),
			'slug'           => array( 'slug', 'x' ),
		);
	}

	public function test_form_encoded_put_cannot_smuggle_fields(): void {
		$token = $this->connect()['access_token'];
		$id    = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'Draft' ), $token )->get_data()['id'];
		foreach ( array( 'PUT', 'PATCH' ) as $method ) {
			$this->use_token( $token );
			$request = new WP_REST_Request( $method, '/wp/v2/posts/' . $id );
			$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
			$request->set_body( 'title=x&sticky=1&template=t' );
			$res = rest_do_request( $request );
			$this->assertSame( 'cpub_field_not_allowed', $res->get_data()['code'] ?? null, $method );
		}
		$this->assertFalse( is_sticky( $id ) );
	}

	public function test_own_unused_image_can_be_deleted(): void {
		$this->ignore_header_warnings();
		$token = $this->connect()['access_token'];
		$this->use_token( $token );
		$upload = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$upload->set_header( 'Content-Type', 'image/png' );
		$upload->set_header( 'Content-Disposition', 'attachment; filename="b.png"' );
		$upload->set_body( file_get_contents( DIR_TESTDATA . '/images/test-image.png' ) );
		$media = rest_do_request( $upload )->get_data()['id'];
		$this->assertSame( 200, $this->api( 'DELETE', '/wp/v2/media/' . $media, array( 'force' => true ), $token )->get_status() );
		$this->assertNull( get_post( $media ) );
	}

	public function test_cannot_attach_an_upload_to_a_published_post(): void {
		$token = $this->connect()['access_token'];
		$post  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => \CPub\Connector\PublisherUser::get()->ID ) );
		$res   = $this->api( 'POST', '/wp/v2/media', array( 'post' => $post ), $token );
		$this->assertSame( 'cpub_media_locked', $res->get_data()['code'] );
	}

	public function test_media_text_block_and_gallery_count_as_uses(): void {
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<!-- wp:media-text {"mediaId":41,"mediaType":"image"} -->' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '[gallery ids="7, 52,9"]' ) );
		foreach ( array( 41, 52 ) as $id ) {
			$att = self::factory()->attachment->create( array( 'import_id' => $id, 'post_mime_type' => 'image/png' ) );
			$this->assertTrue( \CPub\Connector\Auth\RouteGate::media_in_live_use( $att ), "attachment $att" );
		}
	}

	public function test_allowed_post_fields_work(): void {
		$token = $this->connect()['access_token'];
		$cat   = self::factory()->category->create();
		$res   = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'T', 'content' => 'C', 'excerpt' => 'E', 'status' => 'pending', 'categories' => array( $cat ), 'context' => 'edit' ), $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
	}

	public function test_image_in_a_published_post_is_locked(): void {
		$this->ignore_header_warnings();
		$token = $this->connect()['access_token'];
		$this->use_token( $token );
		$upload = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$upload->set_header( 'Content-Type', 'image/png' );
		$upload->set_header( 'Content-Disposition', 'attachment; filename="a.png"' );
		$upload->set_body( file_get_contents( DIR_TESTDATA . '/images/test-image.png' ) );
		$media = rest_do_request( $upload )->get_data()['id'];

		$post = $this->api( 'POST', '/wp/v2/posts', array( 'title' => 'With image', 'content' => '<!-- wp:image {"id":' . $media . '} --><figure class="wp-block-image"><img class="wp-image-' . $media . '"/></figure><!-- /wp:image -->' ), $token )->get_data()['id'];
		$this->assertSame( 200, $this->api( 'POST', '/wp/v2/media/' . $media, array( 'alt_text' => 'Fine while draft' ), $token )->get_status() );

		wp_update_post( array( 'ID' => $post, 'post_status' => 'publish' ) );
		$res = $this->api( 'DELETE', '/wp/v2/media/' . $media, array( 'force' => true ), $token );
		$this->assertSame( 403, $res->get_status() );
		$this->assertContains( $res->get_data()['code'], array( 'cpub_media_locked', 'cpub_field_not_allowed' ) );
		$res = $this->api( 'POST', '/wp/v2/media/' . $media, array( 'alt_text' => 'Changed after publish' ), $token );
		$this->assertSame( 'cpub_media_locked', $res->get_data()['code'] );
		$this->assertNotNull( get_post( $media ) );
	}

	public function test_featured_image_of_a_published_post_is_locked(): void {
		$token = $this->connect()['access_token'];
		$media = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.png' );
		wp_update_post( array( 'ID' => $media, 'post_author' => \CPub\Connector\PublisherUser::get()->ID ) );
		$post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		set_post_thumbnail( $post, $media );
		$this->assertTrue( \CPub\Connector\Auth\RouteGate::media_in_live_use( $media ) );
		$this->assertSame( 'cpub_media_locked', $this->api( 'POST', '/wp/v2/media/' . $media, array( 'alt_text' => 'x' ), $token )->get_data()['code'] );
	}

	public function test_similar_ids_are_not_confused(): void {
		$post = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<img class="wp-image-123"/>' ) );
		$att  = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.png' );
		$this->assertFalse( \CPub\Connector\Auth\RouteGate::media_in_live_use( 12 ), 'wp-image-123 must not lock attachment 12' );
	}
}
