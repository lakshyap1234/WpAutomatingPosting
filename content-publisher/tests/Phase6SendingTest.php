<?php
/**
 * Phase 6: publishing directly when the client site allows it, scheduling,
 * correcting and unpublishing live posts, tags chosen in the editor (created
 * when missing), and keeping files for a while after a post goes live.
 */

use CPub\Publisher\Connections\HealthCheck;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\SiteSettings;
use CPub\Publisher\Installer;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Review;
use CPub\Publisher\Jobs\Sender;

class Phase6SendingTest extends SenderTestCase {

	/** The client site allows direct publishing, and this site sends posts published. */
	private function publishing( bool $site_allows = true, string $send_as = 'publish' ): void {
		$this->client->can_publish = $site_allows;
		HealthCheck::check( Sites::get( (int) $this->site->id ) );
		SiteSettings::save( Sites::get( (int) $this->site->id ), null, true, false, $send_as );
		$this->site = Sites::get( (int) $this->site->id );
	}

	/** The connection was approved with the right to add tags. */
	private function tags_allowed(): void {
		$this->client->granted[] = 'terms:write';
		HealthCheck::check( Sites::get( (int) $this->site->id ) );
	}

	public function test_the_connection_check_learns_the_granted_permissions() {
		// The Connector's token reply doesn't list them; the connection check does.
		$this->assertFalse( Sender::can_create_tags( $this->site ) );
		$this->client->granted[] = 'terms:write';
		HealthCheck::check( $this->site );
		$this->assertTrue( Sender::can_create_tags( $this->site ) );
		$this->assertContains( 'terms:write', \CPub\Publisher\Connections\Credentials::scopes( (int) $this->site->id ) );
		$this->client->granted = array( 'posts:write', 'media:write', 'terms:read', 'account:read', '<bad>' );
		HealthCheck::check( $this->site );
		$this->assertFalse( Sender::can_create_tags( $this->site ) );
		$this->assertNotContains( '<bad>', \CPub\Publisher\Connections\Credentials::scopes( (int) $this->site->id ) );
	}

	private function reopen_and_approve( int $id, string $markdown, array $opts = array() ): void {
		$job = Jobs::get( $id );
		$this->assertTrue( Review::reopen( $job, $job->revision ), 'reopen from ' . $job->status );
		$job = Jobs::get( $id );
		$this->assertTrue( Review::approve( $job, $markdown, $job->revision, $opts ) );
		as_unschedule_all_actions( Sender::HOOK, array( $id ), Sender::GROUP );
		Sender::send( $id );
	}

	public function test_the_daily_check_learns_what_the_site_allows() {
		$this->client->author = 'Sara Editor';
		$this->publishing();
		$this->assertSame( 1, (int) $this->site->can_publish );
		$this->assertSame( 'Sara Editor', $this->site->remote_user );
		$this->client->can_publish = false;
		HealthCheck::check( $this->site );
		$this->assertSame( 0, (int) Sites::get( (int) $this->site->id )->can_publish );
	}

	public function test_publish_is_only_used_when_the_site_allows_it() {
		$this->publishing( false );
		$job    = $this->approved();
		$target = Sender::target( $job, $this->site );
		$this->assertSame( 'draft', $target['status'] );
		$this->assertStringContainsString( 'hasn\'t allowed direct publishing', $target['note'] );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status, (string) $job->last_error );
		$this->assertSame( 'draft', $this->client->posts[ $job->remote_post_id ]['status'] );

		// Allowed by the site, but this site's setting says drafts.
		$this->publishing( true, 'draft' );
		$this->assertSame( 'draft', Sender::target( $this->approved( "# Other\n\nBody\n" ), $this->site )['status'] );
	}

	public function test_a_post_is_published_straight_away_and_its_files_kept() {
		$this->publishing();
		$job = $this->approved();
		$this->assertSame( 'Publish on Client Test now', Sender::target( $job, $this->site )['label'] );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::PUBLISHED, $job->status, (string) $job->last_error );
		$this->assertSame( 'publish', $this->client->posts[ $job->remote_post_id ]['status'] );
		$this->assertNotEmpty( $job->data['published_at'] );
		$this->assertNotEmpty( Assets::for_job( $job->id ), 'files kept for the retention period' );
		$this->assertStringStartsWith( 'Published on Client Test', self::last_event( $job->id ) );
	}

	public function test_a_post_with_a_future_date_is_scheduled() {
		$this->publishing();
		$job  = $this->approved();
		$data = $job->data;
		$data['publish_at'] = gmdate( 'c', time() + 3 * DAY_IN_SECONDS );
		Jobs::update( $job->id, array( 'data' => $data ) );
		$this->assertStringStartsWith( 'Schedule on Client Test for', Sender::target( Jobs::get( $job->id ), $this->site )['label'] );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status );
		$this->assertSame( 'future', $job->remote_post_status );
		$this->assertSame( 'future', $this->client->posts[ $job->remote_post_id ]['status'] );
		$this->assertSame( 'Scheduled', Review::view( $job )['status_text'] );

		// The date arrives; the daily check sees it live.
		$this->client->posts[ $job->remote_post_id ]['status'] = 'publish';
		$this->assertSame( 'publish', Sender::check( $job ) );
		$this->assertSame( Jobs::PUBLISHED, Jobs::get( $job->id )->status );
	}

	public function test_a_live_post_is_corrected_in_place_and_stays_live() {
		$this->publishing();
		$job = $this->approved();
		Sender::send( $job->id );
		$job     = Jobs::get( $job->id );
		$post_id = (int) $job->remote_post_id;
		$this->assertTrue( Review::can( $job )['reopen'] );

		// Even if this site now sends drafts, a correction keeps the live post live.
		SiteSettings::save( $this->site, null, true, false, 'draft' );
		$this->site = Sites::get( (int) $this->site->id );
		$this->assertSame( 'Update the published post on Client Test', Sender::target( $job, $this->site )['label'] );
		$this->reopen_and_approve( $job->id, str_replace( 'grinder matters', 'grinder really matters', $job->markdown ) );

		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::PUBLISHED, $job->status, (string) $job->last_error );
		$this->assertSame( $post_id, (int) $job->remote_post_id );
		$this->assertCount( 1, $this->client->posts, 'updated, not a second post' );
		$this->assertSame( 'publish', $this->client->posts[ $post_id ]['status'] );
		$this->assertStringContainsString( 'really matters', $this->client->posts[ $post_id ]['content'] );
		$this->assertCount( 2, $this->client->media, 'images not uploaded again' );
	}

	public function test_a_live_post_cannot_be_reopened_when_the_site_stops_allowing_it() {
		$this->publishing();
		$job = $this->approved();
		Sender::send( $job->id );
		$this->client->can_publish = false;
		HealthCheck::check( $this->site );
		$job = Jobs::get( $job->id );
		$this->assertFalse( Review::can( $job )['reopen'] );
		$this->assertFalse( Review::can( $job )['unpublish'] );
		$this->assertInstanceOf( WP_Error::class, Review::reopen( $job, $job->revision ) );
		$calls = count( $this->client->calls );
		$res   = Sender::unpublish( $job );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 'cpub_not_allowed', $res->get_error_code() );
		$this->assertCount( $calls, $this->client->calls, 'not even asked' );
		$this->assertSame( 'publish', $this->client->posts[ $job->remote_post_id ]['status'] );
	}

	public function test_unpublish_takes_it_back_to_a_draft_and_it_can_go_live_again() {
		$this->publishing();
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::can( $job )['unpublish'] );
		$this->assertTrue( Sender::unpublish( $job ) );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status );
		$this->assertSame( 'draft', $job->remote_post_status );
		$this->assertSame( 'draft', $this->client->posts[ $job->remote_post_id ]['status'] );
		$this->assertFalse( Review::can( $job )['unpublish'] );
		$this->assertInstanceOf( WP_Error::class, Sender::unpublish( $job ), 'only live posts' );

		// Approving again publishes the same post.
		$this->reopen_and_approve( $job->id, $job->markdown );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::PUBLISHED, $job->status, (string) $job->last_error );
		$this->assertCount( 1, $this->client->posts );
	}

	public function test_tags_chosen_in_the_editor_are_used_and_missing_ones_created() {
		$this->tags_allowed();
		$job  = $this->approved( '', array( array( 'line' => 3, 'kind' => 'metadata', 'text' => 'Tags: from-the-file' ) ) );
		$data = $job->data;
		$data['tags'] = array( 'Coffee', 'Pour-over' ); // the reviewer's choice replaces the Tags: line
		Jobs::update( $job->id, array( 'data' => $data ) );
		Sender::send( $job->id );
		$job  = Jobs::get( $job->id );
		$tags = $this->client->posts[ $job->remote_post_id ]['tags'];
		$this->assertContains( 20, $tags, 'existing tag' );
		$this->assertCount( 2, $tags );
		$new = array_values( array_filter( $this->client->tags, fn( $t ) => 'Pour-over' === $t['name'] ) );
		$this->assertCount( 1, $new, 'created once' );
		$this->assertContains( $new[0]['id'], $tags );
		$this->assertSame( array(), $job->data['sent']['tags_missing'] );
		$this->assertEmpty( array_filter( $this->client->tags, fn( $t ) => 'from-the-file' === $t['name'] ) );
	}

	public function test_without_permission_to_add_tags_missing_ones_are_listed() {
		$job  = $this->approved();
		$data = $job->data;
		$data['tags'] = array( 'coffee', 'Brand New' );
		Jobs::update( $job->id, array( 'data' => $data ) );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( array( 20 ), $this->client->posts[ $job->remote_post_id ]['tags'] );
		$this->assertSame( array( 'Brand New' ), $job->data['sent']['tags_missing'] );
		$this->assertNotContains( 'POST /wp/v2/tags', $this->client->calls );
	}

	public function test_a_tag_made_meanwhile_is_reused() {
		$this->tags_allowed();
		$job  = $this->approved();
		$data = $job->data;
		$data['tags'] = array( 'Latte' );
		Jobs::update( $job->id, array( 'data' => $data ) );
		// Someone adds "latte" on the client site between our search and our create.
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false === $pre && str_contains( $url, rawurlencode( '/wp/v2/tags' ) ) && 'GET' === $args['method'] ) {
					$this->client->tags[] = array( 'id' => 77, 'name' => 'latte' );
					return array( 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ), 'body' => '[]', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
				}
				return $pre;
			},
			4,
			3
		);
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( array( 77 ), $this->client->posts[ $job->remote_post_id ]['tags'] );
	}

	public function test_a_post_can_be_corrected_after_its_files_were_deleted() {
		$this->publishing();
		$job = $this->approved();
		Sender::send( $job->id );
		$job                  = Jobs::get( $job->id );
		$data                 = $job->data;
		$data['published_at'] = gmdate( 'c', time() - ( Sender::RETENTION_DAYS + 1 ) * DAY_IN_SECONDS );
		Jobs::update( $job->id, array( 'data' => $data ) );
		$this->assertSame( 0, Sender::purge_files( (int) $this->site->id ), 'the retention clock started when it went live here' );
		$data['retain_from'] = $data['published_at'];
		Jobs::update( $job->id, array( 'data' => $data ) );
		$this->assertSame( 1, Sender::purge_files( (int) $this->site->id ) );
		$this->assertSame( array(), Assets::for_job( $job->id ) );

		$job = Jobs::get( $job->id );
		$this->reopen_and_approve( $job->id, str_replace( 'End.', 'The end.', $job->markdown ) );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::PUBLISHED, $job->status, (string) $job->last_error );
		$this->assertCount( 2, $this->client->media, 'the client\'s copies are reused' );
		$this->assertStringContainsString( 'The end.', $this->client->posts[ $job->remote_post_id ]['content'] );

		// But a changed caption on a deleted file can't be sent: there is nothing to upload.
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::reopen( $job, $job->revision ) );
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::approve( $job, str_replace( '"Morning cup"', '"Evening cup"', $job->markdown ), $job->revision ) );
		as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP );
		Sender::send( $job->id );
		$this->assertSame( Jobs::SEND_FAILED, Jobs::get( $job->id )->status );
		$this->assertStringContainsString( 'was deleted 30 days after the post went live', Jobs::get( $job->id )->last_error );
	}

	public function test_editor_options_are_validated_and_kept() {
		$job = $this->approved();
		$this->assertTrue( Review::reopen( $job, $job->revision ) );
		$job = Jobs::get( $job->id );
		$this->assertInstanceOf( WP_Error::class, Review::save( $job, $job->markdown, $job->revision, array( 'publish_at' => 'not a date' ) ) );
		$this->assertInstanceOf( WP_Error::class, Review::save( $job, $job->markdown, $job->revision, array( 'tags' => range( 1, 21 ) ) ) );
		$this->assertTrue( Review::save( $job, $job->markdown, $job->revision, array( 'tags' => array( ' Coffee ', 'coffee', '<b>Tea</b>', '' ), 'publish_at' => '2031-05-01T09:30:00Z' ) ) );
		$job = Jobs::get( $job->id );
		$this->assertSame( array( 'Coffee', 'Tea' ), $job->data['tags'] );
		$this->assertSame( '2031-05-01T09:30:00+00:00', $job->data['publish_at'] );
		$this->assertSame( array( 'Coffee', 'Tea' ), Review::view( $job )['tags'] );
		$this->assertTrue( Review::save( $job, $job->markdown, $job->revision, array( 'publish_at' => '' ) ) );
		$this->assertArrayNotHasKey( 'publish_at', Jobs::get( $job->id )->data );
	}

	public function test_rest_routes_for_tags_and_unpublish() {
		do_action( 'rest_api_init' );
		$this->publishing();
		$res = rest_do_request( new WP_REST_Request( 'GET', "/cpub/v1/sites/{$this->site->id}/tags" ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'coffee', 'Home Brewing' ), $res->get_data()['tags'] );
		$this->assertFalse( $res->get_data()['can_create'] );

		$job = $this->approved();
		$this->assertTrue( Review::reopen( $job, $job->revision ) );
		$req = new WP_REST_Request( 'POST', "/cpub/v1/jobs/{$job->id}/approve" );
		$req->set_param( 'markdown', Jobs::get( $job->id )->markdown );
		$req->set_param( 'revision', Jobs::get( $job->id )->revision );
		$req->set_param( 'tags', array( 'coffee' ) );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertSame( array( 'coffee' ), Jobs::get( $job->id )->data['tags'] );
		as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP );
		Sender::send( $job->id );
		$v = rest_do_request( new WP_REST_Request( 'POST', "/cpub/v1/jobs/{$job->id}/unpublish" ) )->get_data();
		$this->assertSame( 'sent', $v['status'] );
		$this->assertSame( 'draft', $v['remote_post_status'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', "/cpub/v1/sites/{$this->site->id}/tags" ) )->get_status() );
	}
}
