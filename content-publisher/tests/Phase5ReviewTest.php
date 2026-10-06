<?php
/**
 * Regression tests for the Phase 5 review findings (sending).
 */

use CPub\Publisher\Installer;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Review;
use CPub\Publisher\Jobs\SendException;
use CPub\Publisher\Jobs\Sender;
use CPub\Publisher\Jobs\Storage;
use CPub\Publisher\Support\Events;

class Phase5ReviewTest extends SenderTestCase {

	/** Every outgoing request URL and its args, before anything answers it. */
	private array $requests = array();

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array( $url, $args );
				return $pre;
			},
			1,
			3
		);
	}

	private function send_to_review_and_approve( object $job, string $markdown ): void {
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::reopen( $job, $job->revision ) );
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::approve( $job, $markdown, $job->revision ) );
	}

	// M1. An image is reused from an earlier attempt only if its file, alt text and caption are unchanged.
	public function test_changed_images_are_uploaded_again_and_unchanged_ones_reused() {
		$job                                         = $this->approved();
		$this->client->fail_next['POST /wp/v2/posts'] = 403; // the images go up, then the draft is refused
		Sender::send( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, Jobs::get( $job->id )->status );
		$this->assertCount( 2, $this->client->media );
		[ $old_cup, $old_web ] = array_keys( $this->client->media );

		// The reviewer changes the caption of the uploaded file; the web image stays as it was.
		$markdown = str_replace( '"Morning cup"', '"Evening cup"', Jobs::get( $job->id )->markdown );
		$this->send_to_review_and_approve( $job, $markdown );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status, (string) $job->last_error );
		$this->assertCount( 3, $this->client->media, 'only the changed image was uploaded again' );
		$new_cup = array_key_last( $this->client->media );
		$this->assertSame( 'Evening cup', $this->client->media[ $new_cup ]['caption'] );
		$content = $this->client->posts[ $job->remote_post_id ]['content'];
		$this->assertStringContainsString( '"id":' . $new_cup, $content );
		$this->assertStringContainsString( '"id":' . $old_web, $content );
		$this->assertStringNotContainsString( '"id":' . $old_cup . ',', $content );
	}

	// M1. The media map is built from the images in the post now: a removed image isn't attached or counted.
	public function test_images_removed_before_a_resend_are_left_out() {
		$job                                         = $this->approved();
		$this->client->fail_next['POST /wp/v2/posts'] = 403;
		Sender::send( $job->id );
		$markdown = preg_replace( '/!\[From the web\]\([^)]+\)\n/', '', Jobs::get( $job->id )->markdown );
		$this->send_to_review_and_approve( $job, $markdown );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status, (string) $job->last_error );
		$this->assertSame( 1, $job->data['sent']['images'] );
		$attached = array_filter( $this->client->media, fn( $m ) => (int) $m['post'] === (int) $job->remote_post_id );
		$this->assertCount( 1, $attached );
	}

	// M2. A draft the client binned is replaced by a new one when sent again.
	public function test_a_binned_draft_is_replaced_when_sent_again() {
		$job = $this->approved();
		Sender::send( $job->id );
		$first = (int) Jobs::get( $job->id )->remote_post_id;
		$this->client->posts[ $first ]['status'] = 'trash';
		$this->assertSame( 'deleted', Sender::check( Jobs::get( $job->id ) ) );
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::can( $job )['send'] );
		$this->assertTrue( Sender::retry( $job ) );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status, (string) $job->last_error );
		$this->assertNotSame( $first, (int) $job->remote_post_id );
		$this->assertCount( 2, $this->client->posts );
		$this->assertSame( 'draft', $this->client->posts[ $job->remote_post_id ]['status'] );
		$this->assertArrayNotHasKey( 'client_deleted', $job->data );
	}

	// M2. A draft the client already published is never overwritten; the send fails with a clear reason.
	public function test_a_draft_the_client_published_meanwhile_is_not_overwritten() {
		$job                                          = $this->approved();
		$this->client->lose_reply['POST /wp/v2/posts'] = true;
		Sender::send( $job->id );
		$this->assertSame( Jobs::APPROVED, Jobs::get( $job->id )->status );
		$post_id = array_key_first( $this->client->posts );
		$this->client->posts[ $post_id ]['status'] = 'publish';
		as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP );
		$calls = count( $this->client->calls );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, $job->status );
		$this->assertStringContainsString( 'the client has published it (post ' . $post_id . ')', $job->last_error );
		$this->assertNotContains( 'POST /wp/v2/posts/' . $post_id, array_slice( $this->client->calls, $calls ) );
	}

	// M3. Only public addresses are fetched.
	public function test_address_classification() {
		foreach ( array( '10.0.0.1', '127.0.0.1', '169.254.169.254', '100.64.0.1', '192.168.1.1', '0.0.0.0', '::1', 'fe80::1', 'fd00::1', '::ffff:10.0.0.1', '::ffff:127.0.0.1' ) as $ip ) {
			$this->assertFalse( Sender::is_public_ip( $ip ), $ip );
		}
		foreach ( array( '8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '::ffff:8.8.8.8' ) as $ip ) {
			$this->assertTrue( Sender::is_public_ip( $ip ), $ip );
		}
		foreach ( array( 'http://localhost/x.png', 'http://[::1]/x.png', 'http://169.254.169.254/latest/meta-data', 'http://10.1.2.3/x.png' ) as $url ) {
			try {
				Sender::public_address( $url );
				$this->fail( "$url was allowed" );
			} catch ( SendException $e ) {
				$this->assertStringContainsString( 'private or local network address', $e->getMessage(), $url );
				$this->assertFalse( $e->transient );
			}
		}
	}

	// M3. Redirects are followed by hand, and each hop is checked: a public image can't bounce us inward.
	public function test_redirects_are_checked_hop_by_hop() {
		$this->web['https://images.example/bounce.png'] = array( 302, '', array( 'location' => 'http://127.0.0.1/secret.png' ) );
		$job = $this->approved( "# R\n\n![x](https://images.example/bounce.png)\n" );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, $job->status );
		$this->assertStringContainsString( 'private or local network address', $job->last_error );
		$this->assertSame( array(), array_filter( $this->requests, fn( $r ) => str_contains( $r[0], '127.0.0.1' ) ), 'the private address was never requested' );

		// A relative redirect to a public image works, and WordPress is never left to follow redirects itself.
		$this->web['https://images.example/moved.png'] = array( 301, '', array( 'location' => '/cup.png' ) );
		$job = $this->approved( "# R2\n\n![x](https://images.example/moved.png)\n" );
		Sender::send( $job->id );
		$this->assertSame( Jobs::SENT, Jobs::get( $job->id )->status, (string) Jobs::get( $job->id )->last_error );
		foreach ( array_filter( $this->requests, fn( $r ) => str_starts_with( $r[0], 'https://images.example/' ) ) as $r ) {
			$this->assertSame( 0, $r[1]['redirection'], $r[0] );
		}

		// A redirect loop stops.
		$this->web['https://images.example/loop.png'] = array( 302, '', array( 'location' => 'https://images.example/loop.png' ) );
		$job = $this->approved( "# R3\n\n![x](https://images.example/loop.png)\n" );
		Sender::send( $job->id );
		$this->assertStringContainsString( 'too many redirects', Jobs::get( $job->id )->last_error );
	}

	// L1. Approving again starts sending afresh: old retry counts and waiting retries are cleared.
	public function test_approving_again_resets_the_send_state() {
		$job                  = $this->approved();
		$data                 = $job->data;
		$data['send_retries'] = 3;
		$data['send_cut_off'] = 1;
		Jobs::update( $job->id, array( 'last_error' => 'old problem', 'data' => $data ) );
		Sender::queue( $job->id, 900 ); // an old retry waiting its turn
		$this->send_to_review_and_approve( $job, Jobs::get( $job->id )->markdown );
		$job = Jobs::get( $job->id );
		$this->assertSame( 0, $job->data['send_retries'] );
		$this->assertSame( 0, $job->data['send_cut_off'] );
		$this->assertNull( $job->last_error );
		$pending = as_get_scheduled_actions( array( 'hook' => Sender::HOOK, 'args' => array( $job->id ), 'group' => Sender::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' );
		$this->assertCount( 1, $pending, 'one send, not the old retry as well' );
		$this->assertLessThanOrEqual( time() + 5, $this->scheduled( $job->id ), 'sent now, not when the old retry was due' );
	}

	// L2. A reply without a post id is not taken as success.
	public function test_a_created_reply_without_a_post_id_is_retried() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false === $pre && str_contains( $url, rawurlencode( '/wp/v2/posts' ) ) && ! preg_match( '#posts%2F\d#', $url ) && 'POST' === $args['method'] ) {
					return array( 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ), 'body' => '{"status":"draft"}', 'response' => array( 'code' => 201, 'message' => '' ), 'cookies' => array(), 'filename' => null );
				}
				return $pre;
			},
			4,
			3
		);
		$job = $this->approved( "# No id\n\nBody\n" );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::APPROVED, $job->status, 'waiting to retry, not "sent"' );
		$this->assertStringContainsString( 'didn\'t say which post it is', $job->last_error );
		$this->assertNull( $job->remote_post_id );
	}

	// L3. If the job changed state while the draft was being made, the draft's id is still kept.
	public function test_the_draft_id_is_kept_when_the_job_moved_on_meanwhile() {
		global $wpdb;
		$job = $this->approved();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $wpdb, $job ) {
				if ( false === $pre && preg_match( '#media%2F\d#', $url ) ) { // attaching an image: the draft exists now
					$wpdb->update( Installer::table( 'jobs' ), array( 'status' => Jobs::APPROVED ), array( 'id' => $job->id ) ); // e.g. recovered as cut off
				}
				return $pre;
			},
			4,
			3
		);
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::APPROVED, $job->status );
		$this->assertSame( array_key_first( $this->client->posts ), (int) $job->remote_post_id );
		$this->assertStringContainsString( 'had changed state here meanwhile', self::last_event( $job->id ) );
	}

	// L5. Tags are found by name however many tags the site has.
	public function test_tags_are_found_on_sites_with_many_tags() {
		$this->client->tags = array();
		for ( $i = 1; $i <= 150; $i++ ) {
			$this->client->tags[] = array( 'id' => 1000 + $i, 'name' => 'tag ' . $i );
		}
		$this->client->tags[] = array( 'id' => 2000, 'name' => 'Pour-over' );
		$job = $this->approved( "# T\n\nBody\n", array( array( 'line' => 3, 'kind' => 'metadata', 'text' => 'Tags: pour-over, missing one' ) ) );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( array( 2000 ), $this->client->posts[ $job->remote_post_id ]['tags'] );
		$this->assertSame( array( 'missing one' ), $job->data['sent']['tags_missing'] );
	}

	// L6. The daily check covers every sent post, not just the first page, and doesn't skip any as others leave "sent".
	public function test_the_daily_check_covers_every_sent_post() {
		$ids = array();
		for ( $i = 0; $i < 205; $i++ ) {
			$post                        = 5000 + $i;
			$this->client->posts[ $post ] = array( 'id' => $post, 'status' => $i < 3 || $i >= 200 ? 'publish' : 'draft', 'link' => "https://client.test/?p=$post" );
			$ids[]                       = Jobs::create( array( 'site_id' => (int) $this->site->id, 'status' => Jobs::SENT, 'remote_post_id' => $post, 'remote_post_status' => 'draft', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 10000 + $i ) ) );
		}
		Sender::check_site( $this->site->id );
		foreach ( array( 0, 1, 2, 200, 201, 202, 203, 204 ) as $i ) {
			$this->assertSame( Jobs::PUBLISHED, Jobs::get( $ids[ $i ] )->status, "post $i" );
		}
		$this->assertSame( Jobs::SENT, Jobs::get( $ids[100] )->status );
	}

	// L7. A draft restored from the client's bin is no longer marked deleted.
	public function test_a_restored_draft_is_no_longer_marked_deleted() {
		$job = $this->approved();
		Sender::send( $job->id );
		$job  = Jobs::get( $job->id );
		$post = $this->client->posts[ $job->remote_post_id ];
		unset( $this->client->posts[ $job->remote_post_id ] );
		$this->assertSame( 'deleted', Sender::check( $job ) );
		$this->assertTrue( Review::can( Jobs::get( $job->id ) )['send'] );
		$this->client->posts[ $job->remote_post_id ] = $post;
		$this->assertSame( 'draft', Sender::check( Jobs::get( $job->id ) ) );
		$job = Jobs::get( $job->id );
		$this->assertArrayNotHasKey( 'client_deleted', $job->data );
		$this->assertFalse( Review::can( $job )['send'] );
	}

	// L8. Private or scheduled isn't published: our files are kept until the post is public.
	public function test_only_public_posts_count_as_published() {
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$dir = Storage::base() . '/' . $job->data['dir'];
		foreach ( array( 'private', 'future', 'pending' ) as $status ) {
			$this->client->posts[ $job->remote_post_id ]['status'] = $status;
			Sender::check( Jobs::get( $job->id ) );
			$this->assertSame( Jobs::SENT, Jobs::get( $job->id )->status, $status );
			$this->assertSame( $status, Jobs::get( $job->id )->remote_post_status );
			$this->assertDirectoryExists( $dir, $status );
		}
		$this->assertNotEmpty( Assets::for_job( $job->id ) );
	}
}
