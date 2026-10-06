<?php

use CPub\Connector\Installer;
use CPub\Connector\OAuth\Grants;

/** Phase 2 review: lost renewal replies, and the iss parameter. */
class ReissueAndIssuerTest extends OAuthTestCase {

	public function test_repeated_renewal_within_grace_reissues_and_retires_the_lost_pair(): void {
		$first = $this->connect();
		$lost  = $this->refresh( $first['refresh_token'] )->get_data(); // reply "lost" on the way back
		$again = $this->refresh( $first['refresh_token'] );
		$this->assertSame( 200, $again->get_status(), wp_json_encode( $again->get_data() ) );
		$this->assertNotNull( Grants::active() );

		$new = $again->get_data();
		$this->assertSame( 200, $this->api( 'GET', '/wp/v2/users/me', array(), $new['access_token'] )->get_status() );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $lost['access_token'] )->get_status(), 'The lost pair is retired.' );
		$this->assertSame( 200, $this->refresh( $new['refresh_token'] )->get_status(), 'The chain continues normally.' );
	}

	public function test_a_copy_used_in_the_grace_window_is_caught_on_the_next_real_renewal(): void {
		$first  = $this->connect();
		$legit  = $this->refresh( $first['refresh_token'] )->get_data();   // agency renews
		$thief  = $this->refresh( $first['refresh_token'] );               // thief replays within 2 minutes
		$this->assertSame( 200, $thief->get_status() );
		$next   = $this->refresh( $legit['refresh_token'] );              // agency's next renewal: its token was retired
		$this->assertSame( 400, $next->get_status() );
		$this->assertNull( Grants::active(), 'Reuse detected: whole connection cut, thief included.' );
		$this->assertSame( 401, $this->api( 'GET', '/wp/v2/users/me', array(), $thief->get_data()['access_token'] )->get_status() );
	}

	public function test_no_reissue_after_the_grace_window(): void {
		global $wpdb;
		$first = $this->connect();
		$this->refresh( $first['refresh_token'] );
		$wpdb->query( 'UPDATE ' . Installer::table( 'tokens' ) . " SET used_at = '2000-01-01 00:00:00' WHERE used_at IS NOT NULL AND kind = 'refresh'" );
		$this->assertSame( 400, $this->refresh( $first['refresh_token'] )->get_status() );
		$this->assertNull( Grants::active() );
	}

	public function test_no_reissue_once_the_replacement_was_used(): void {
		$first  = $this->connect();
		$second = $this->refresh( $first['refresh_token'] )->get_data();
		$this->refresh( $second['refresh_token'] ); // replacement used: the chain moved on
		$this->assertSame( 400, $this->refresh( $first['refresh_token'] )->get_status() );
		$this->assertNull( Grants::active() );
	}

	public function test_only_one_reissue_per_used_token(): void {
		$first = $this->connect();
		$this->refresh( $first['refresh_token'] );
		$this->assertSame( 200, $this->refresh( $first['refresh_token'] )->get_status(), 'Second presentation: reissued once.' );
		$this->assertSame( 400, $this->refresh( $first['refresh_token'] )->get_status(), 'Third presentation: reuse.' );
		$this->assertNull( Grants::active() );
	}

	public function test_approval_and_denial_name_the_issuer(): void {
		$ok = $this->consent( $this->authorize_query(), 'approve' );
		$this->assertSame( home_url( '/' ), self::query_of( $ok['redirect'] )['iss'] );
		$no = $this->consent( $this->authorize_query(), 'deny' );
		$this->assertSame( home_url( '/' ), self::query_of( $no['redirect'] )['iss'] );
		$meta = rest_do_request( new WP_REST_Request( 'GET', '/cpub-connector/v1/oauth/metadata' ) )->get_data();
		$this->assertTrue( $meta['authorization_response_iss_parameter_supported'] );
		$this->assertSame( home_url( '/' ), $meta['issuer'] );
	}
}
