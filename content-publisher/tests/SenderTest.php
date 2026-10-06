<?php
/**
 * Sending approved posts to the (fake) client site, and what happens after.
 */

use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\SiteSettings;
use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Jobs\Review;
use CPub\Publisher\Jobs\Sender;
use CPub\Publisher\Support\Events;

class SenderTest extends SenderTestCase {

	public function test_approving_queues_the_send() {
		$r   = Intake::submit( (int) $this->site->id, array( array( 'name' => 'p.md', 'bytes' => "# T\n\nBody\n" ) ), array(), $this->admin );
		$id  = $r['created'][0];
		Processor::process( $id );
		$job = Jobs::get( $id );
		$this->assertTrue( Review::approve( $job, $job->markdown, $job->revision ) );
		$this->assertNotNull( $this->scheduled( $id ) );
	}

	public function test_a_post_goes_end_to_end_as_a_draft() {
		SiteSettings::save( Sites::get( (int) $this->site->id ), 5, true );
		$job = $this->approved( '', array( array( 'line' => 9, 'kind' => 'metadata', 'text' => 'Tags: Coffee, home brewing, grinders' ) ) );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status, (string) $job->last_error );

		// Two images uploaded with alt text and caption, as images.
		$this->assertCount( 2, $this->client->media );
		$media = array_values( $this->client->media );
		$this->assertSame( 'A cup', $media[0]['alt_text'] );
		$this->assertSame( 'Morning cup', $media[0]['caption'] );
		$this->assertSame( 'image/png', $media[0]['type'] );
		$this->assertStringContainsString( 'filename="cup-file.png"', $media[0]['filename'] );
		$this->assertStringContainsString( 'filename="cup.png"', $media[1]['filename'] );
		$this->assertSame( array( 'https://images.example/cup.png' ), $this->downloads );

		// One draft, with the uploaded images in its blocks, category and the tags that exist.
		$this->assertCount( 1, $this->client->posts );
		$post = array_values( $this->client->posts )[0];
		$this->assertSame( 'draft', $post['status'] );
		$this->assertSame( 'Coffee at home', $post['title'] );
		$this->assertStringContainsString( '<!-- wp:image {"id":' . $media[0]['id'] . ',"sizeSlug":"large","linkDestination":"none"} -->', $post['content'] );
		$this->assertStringContainsString( 'class="wp-image-' . $media[1]['id'] . '"', $post['content'] );
		$this->assertStringContainsString( '<strong>good</strong>', $post['content'] );
		$this->assertSame( array( 5 ), $post['categories'] );
		$this->assertSame( array( 20, 21 ), $post['tags'] );
		$this->assertArrayNotHasKey( 'featured_media', $post );
		foreach ( $this->client->media as $m ) {
			$this->assertSame( $post['id'], $m['post'], 'attached to the draft' );
		}

		// What the reviewer sees.
		$this->assertSame( $post['id'], (int) $job->remote_post_id );
		$this->assertSame( 'https://client.test/wp-admin/post.php?post=' . $post['id'] . '&action=edit', $job->data['sent']['edit_url'] );
		$this->assertSame( "https://client.test/?p={$post['id']}&preview=true", $job->data['sent']['preview_url'] );
		$this->assertSame( array( 'grinders' ), $job->data['sent']['tags_missing'] );
		$this->assertSame( 'Coffee & Tea', $job->data['sent']['category'] );
		$this->assertMatchesRegularExpression( '/Sent to .* as a draft .*Tags not on the site \(not added\): grinders/', self::last_event( $job->id ) );
	}

	public function test_category_line_and_featured_image() {
		SiteSettings::save( Sites::get( (int) $this->site->id ), 5, true, true );
		$job = $this->approved( '', array( array( 'line' => 9, 'kind' => 'metadata', 'text' => 'Category: espresso' ) ) );
		Sender::send( $job->id );
		$post = array_values( $this->client->posts )[0];
		$this->assertSame( array( 6 ), $post['categories'] );
		$this->assertSame( array_keys( $this->client->media )[0], $post['featured_media'] );

		// With the override off, the default wins; with no default, the site's own default applies (no categories sent).
		SiteSettings::save( Sites::get( (int) $this->site->id ), 0, false );
		$job = $this->approved( "# Two\n\nBody\n", array( array( 'line' => 3, 'kind' => 'metadata', 'text' => 'Category: espresso' ) ) );
		Sender::send( $job->id );
		$post = array_values( $this->client->posts )[1];
		$this->assertArrayNotHasKey( 'categories', $post );
	}

	public function test_a_lost_reply_never_makes_a_second_draft_or_image() {
		$job                                          = $this->approved();
		$this->client->lose_reply['POST /wp/v2/posts'] = true;
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::APPROVED, $job->status, 'waiting to retry' );
		$this->assertStringContainsString( 'timed out', $job->last_error );
		$this->assertEqualsWithDelta( time() + Sender::RETRY_DELAYS[0], $this->scheduled( $job->id ), 5 );
		$this->assertCount( 1, $this->client->posts, 'the client did create it' );

		as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status );
		$this->assertCount( 1, $this->client->posts, 'no second draft' );
		$this->assertCount( 2, $this->client->media, 'images not uploaded again' );
		$this->assertSame( array_keys( $this->client->posts )[0], (int) $job->remote_post_id );
		$this->assertStringContainsString( 'An earlier attempt had already created it', self::last_event( $job->id ) );
	}

	public function test_a_lost_image_reply_is_not_uploaded_twice() {
		$job                                          = $this->approved();
		$this->client->lose_reply['POST /wp/v2/media'] = true;
		Sender::send( $job->id );
		$this->assertSame( Jobs::APPROVED, Jobs::get( $job->id )->status );
		Sender::send( $job->id );
		$this->assertSame( Jobs::SENT, Jobs::get( $job->id )->status );
		$this->assertCount( 2, $this->client->media );
	}

	public function test_temporary_problems_are_retried_then_fail_and_can_be_sent_again() {
		$job = $this->approved();
		foreach ( Sender::RETRY_DELAYS as $i => $delay ) {
			$this->client->fail_next['POST /wp/v2/media'] = 503;
			as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP );
			Sender::send( $job->id );
			$now = Jobs::get( $job->id );
			$this->assertSame( Jobs::APPROVED, $now->status, "try {$i}" );
			$this->assertEqualsWithDelta( time() + $delay, $this->scheduled( $job->id ), 5 );
		}
		$this->client->fail_next['POST /wp/v2/media'] = 503;
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, $job->status );
		$this->assertStringContainsString( 'Uploading image cup-file.png failed: HTTP 503', $job->last_error );
		$this->assertTrue( Review::can( $job )['send'] );
		$this->assertTrue( Review::can( $job )['reopen'] );

		$this->assertTrue( Sender::retry( $job ) );
		Sender::send( $job->id );
		$this->assertSame( Jobs::SENT, Jobs::get( $job->id )->status );
		$this->assertFalse( Sender::retry( Jobs::get( $job->id ) ), 'only failed sends are retried' );
	}

	public function test_refusals_fail_at_once() {
		$job                                          = $this->approved();
		$this->client->fail_next['POST /wp/v2/posts'] = 403;
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, $job->status );
		$this->assertStringContainsString( 'Connector refused it', $job->last_error );
		$this->assertNull( $this->scheduled( $job->id ) );
	}

	public function test_old_connectors_are_refused_until_updated() {
		$this->client->version = '0.3.0';
		Sites::update( (int) $this->site->id, array( 'connector_version' => '0.3.0' ) );
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, $job->status );
		$this->assertStringContainsString( 'runs Content Publisher Connector 0.3.0. Sending needs 0.4.0', $job->last_error );
		$this->assertSame( array(), $this->client->media, 'nothing sent' );

		$this->client->version = '0.4.0'; // the client installed the update
		Sender::retry( $job );
		Sender::send( $job->id );
		$this->assertSame( Jobs::SENT, Jobs::get( $job->id )->status );
		$this->assertSame( '0.4.0', Sites::get( (int) $this->site->id )->connector_version );
	}

	public function test_image_download_problems() {
		// A private address is refused by WordPress's safe download.
		$job = $this->approved( "# T\n\n![x](http://127.0.0.1/secret.png)\n" );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, $job->status );
		$this->assertStringContainsString( 'Couldn\'t download image http://127.0.0.1/secret.png', $job->last_error );

		// Missing image: fails at once. Server error: retried.
		$job = $this->approved( "# T2\n\n![x](https://images.example/missing.png)\n" );
		Sender::send( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, Jobs::get( $job->id )->status );
		$this->web['https://images.example/busy.png'] = array( 503, '' );
		$job = $this->approved( "# T3\n\n![x](https://images.example/busy.png)\n" );
		Sender::send( $job->id );
		$this->assertSame( Jobs::APPROVED, Jobs::get( $job->id )->status );

		// Not an image at all.
		$this->web['https://images.example/page.png'] = array( 200, '<html>not an image</html>' );
		$job = $this->approved( "# T4\n\n![x](https://images.example/page.png)\n" );
		Sender::send( $job->id );
		$this->assertStringContainsString( 'isn\'t a JPEG, PNG, GIF or WebP', Jobs::get( $job->id )->last_error );
		$this->assertSame( array(), $this->client->posts, 'no draft without its images' );
	}

	public function test_reopened_or_deleted_posts_are_not_sent() {
		$job = $this->approved();
		$this->assertTrue( Review::reopen( $job, $job->revision ) );
		Sender::send( $job->id );
		$this->assertSame( Jobs::REVIEW, Jobs::get( $job->id )->status );
		$this->assertSame( array(), $this->client->posts );
	}

	public function test_sent_drafts_can_be_reopened_but_not_while_sending() {
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::can( $job )['reopen'], 'a sent draft can be corrected and sent again (Phase 6)' );
		$this->assertFalse( Review::can( $job )['edit'] );
		$this->assertTrue( Review::can( $job )['check'] );
		Jobs::update( $job->id, array( 'status' => Jobs::SENDING ) );
		$this->assertFalse( Review::can( Jobs::get( $job->id ) )['reopen'] );
		$this->assertInstanceOf( WP_Error::class, Review::reopen( Jobs::get( $job->id ), Jobs::get( $job->id )->revision ) );
		$this->assertInstanceOf( WP_Error::class, Review::delete( Jobs::get( $job->id ) ) );
	}

	public function test_published_posts_lose_our_copies_of_the_files_after_the_retention_period() {
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( 'draft', Sender::check( $job ) );
		$this->assertSame( Jobs::SENT, Jobs::get( $job->id )->status );

		$this->client->posts[ $job->remote_post_id ]['status'] = 'publish';
		$dir = \CPub\Publisher\Jobs\Storage::base() . '/' . $job->data['dir'];
		$this->assertDirectoryExists( $dir );
		Sender::check_site( $this->site->id ); // the daily run
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::PUBLISHED, $job->status );
		$this->assertDirectoryExists( $dir, 'kept for the retention period' );
		Sender::check_site( $this->site->id );
		$this->assertDirectoryExists( $dir );

		$data                 = $job->data;
		$data['published_at'] = gmdate( 'c', time() - ( Sender::RETENTION_DAYS + 1 ) * DAY_IN_SECONDS );
		Jobs::update( $job->id, array( 'data' => $data ) );
		Sender::check_site( $this->site->id );
		$job = Jobs::get( $job->id );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertNotEmpty( $job->data['files_deleted'] );
		$this->assertSame( array(), Assets::for_job( $job->id ) );
		$this->assertNotEmpty( $job->markdown, 'the record stays' );
	}

	public function test_a_draft_deleted_on_the_client_is_noted_once() {
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		unset( $this->client->posts[ $job->remote_post_id ] );
		$this->assertSame( 'deleted', Sender::check( $job ) );
		$this->assertSame( 'deleted', Sender::check( Jobs::get( $job->id ) ) );
		$this->assertCount( 1, array_filter( Events::for_job( $job->id ), fn( $e ) => 'client_deleted' === $e->type ) );
		$this->assertNotEmpty( Assets::for_job( $job->id ), 'files kept' );
	}

	public function test_cut_off_sending_is_retried_once() {
		global $wpdb;
		$job = $this->approved();
		$old = gmdate( 'Y-m-d H:i:s', time() - Processor::STALE_AFTER - 60 );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \CPub\Publisher\Installer::table( 'jobs' ) . " SET status = 'sending', updated_at = %s WHERE id = %d", $old, $job->id ) ); // phpcs:ignore
		Processor::recover_stale();
		$this->assertSame( Jobs::APPROVED, Jobs::get( $job->id )->status );
		$this->assertNotNull( $this->scheduled( $job->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \CPub\Publisher\Installer::table( 'jobs' ) . " SET status = 'sending', updated_at = %s WHERE id = %d", $old, $job->id ) ); // phpcs:ignore
		Processor::recover_stale();
		$this->assertSame( Jobs::SEND_FAILED, Jobs::get( $job->id )->status );
	}

	public function test_a_disconnected_site_fails_with_a_clear_message() {
		$job = $this->approved();
		Sites::update( (int) $this->site->id, array( 'status' => Sites::DISCONNECTED ) );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, $job->status );
		$this->assertStringContainsString( 'is not connected. Reconnect it', $job->last_error );
	}

	public function test_send_and_check_over_rest() {
		do_action( 'rest_api_init' );
		$job                                          = $this->approved();
		$this->client->fail_next['POST /wp/v2/posts'] = 403;
		Sender::send( $job->id );
		$res = rest_do_request( new WP_REST_Request( 'POST', "/cpub/v1/jobs/{$job->id}/send" ) );
		$this->assertSame( 'approved', $res->get_data()['status'] );
		Sender::send( $job->id );
		$v = rest_do_request( new WP_REST_Request( 'GET', "/cpub/v1/jobs/{$job->id}" ) )->get_data();
		$this->assertSame( 'sent', $v['status'] );
		$this->assertStringContainsString( 'action=edit', $v['sent']['edit_url'] );
		$this->client->posts[ $v['remote_post_id'] ]['status'] = 'publish';
		$v = rest_do_request( new WP_REST_Request( 'POST', "/cpub/v1/jobs/{$job->id}/check" ) )->get_data();
		$this->assertSame( 'published', $v['status'] );
		$this->assertNotEmpty( $v['images'], 'files kept for the retention period' );
	}
}
