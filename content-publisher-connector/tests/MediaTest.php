<?php

/** Uploads through a token: images only. */
class MediaTest extends OAuthTestCase {

	private function upload( string $name, string $bytes, string $token ): WP_REST_Response {
		$this->ignore_header_warnings();
		$this->use_token( $token );
		$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
		$request->set_header( 'Content-Type', 'application/octet-stream' );
		$request->set_header( 'Content-Disposition', 'attachment; filename="' . $name . '"' );
		$request->set_body( $bytes );
		return rest_do_request( $request );
	}

	public function test_image_upload_works(): void {
		$token = $this->connect()['access_token'];
		$png   = file_get_contents( DIR_TESTDATA . '/images/test-image.png' );
		$res   = $this->upload( 'photo.png', $png, $token );
		$this->assertSame( 201, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame( 'image', $res->get_data()['media_type'] );
	}

	public function test_non_image_upload_is_refused_even_if_named_like_an_image(): void {
		$token = $this->connect()['access_token'];
		$res   = $this->upload( 'photo.png', '<?php echo "hi";', $token );
		$this->assertNotSame( 201, $res->get_status() );
		$res = $this->upload( 'notes.txt', 'plain text', $token );
		$this->assertNotSame( 201, $res->get_status() );
	}
}
