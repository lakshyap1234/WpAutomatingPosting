<?php
/**
 * Regression tests for the Phase 6 review findings (Publisher).
 */

use CPub\Publisher\Connections\HealthCheck;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\SiteSettings;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Review;
use CPub\Publisher\Jobs\Sender;

class Phase6ReviewTest extends SenderTestCase {

	private function publishing( bool $site_allows = true, string $send_as = 'publish' ): void {
		$this->client->can_publish = $site_allows;
		HealthCheck::check( Sites::get( (int) $this->site->id ) );
		SiteSettings::save( Sites::get( (int) $this->site->id ), null, true, false, $send_as );
		$this->site = Sites::get( (int) $this->site->id );
	}

	/** Reopen, change the text, approve (as the editor would), and let the queue send it. */
	private function correct( int $id, string $from, string $to ): object {
		$job = Jobs::get( $id );
		$this->assertTrue( Review::reopen( $job, $job->revision ), 'reopen from ' . $job->status );
		$job = Jobs::get( $id );
		$this->assertTrue( Review::approve( $job, str_replace( $from, $to, $job->markdown ), $job->revision ) );
		as_unschedule_all_actions( Sender::HOOK, array( $id ), Sender::GROUP );
		Sender::send( $id );
		return Jobs::get( $id );
	}

	private function live_post(): object {
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::PUBLISHED, $job->status, (string) $job->last_error );
		return $job;
	}

	// 1. A post the client took down is never put back by a correction.
	public function test_a_correction_never_republishes_a_post_the_client_took_down() {
		$this->publishing();
		$job = $this->live_post();
		$id  = (int) $job->remote_post_id;
		$this->client->posts[ $id ]['status'] = 'draft'; // taken down on the site; we haven't looked yet
		$job = $this->correct( $job->id, 'grinder matters', 'grinder really matters' );
		$this->assertSame( 'draft', $this->client->posts[ $id ]['status'], 'not live again' );
		$this->assertStringContainsString( 'really matters', $this->client->posts[ $id ]['content'] );
		$this->assertSame( Jobs::SENT, $job->status );
		$this->assertSame( 'draft', $job->remote_post_status );
	}

	public function test_the_daily_check_notices_a_post_taken_down_or_deleted() {
		$this->publishing();
		$job = $this->live_post();
		$this->client->posts[ $job->remote_post_id ]['status'] = 'draft';
		Sender::check_site( $this->site->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status );
		$this->assertSame( 'draft', $job->remote_post_status );
		$this->assertStringContainsString( 'took the post down', self::last_event( $job->id ) );
		// With "Send as: publish", approving it again publishes it, and says so plainly.
		$target = Sender::target( $job, $this->site );
		$this->assertSame( 'Publish on Client Test now', $target['label'] );
		$this->assertStringContainsString( 'took this post down', $target['note'] );
		SiteSettings::save( $this->site, null, true, false, 'draft' );
		$this->assertSame( 'Update the draft on Client Test', Sender::target( $job, Sites::get( (int) $this->site->id ) )['label'] );
		SiteSettings::save( $this->site, null, true, false, 'publish' );

		$other = $this->live_post_from( "# Second\n\nBody\n" );
		unset( $this->client->posts[ $other->remote_post_id ] );
		Sender::check_site( $this->site->id );
		$other = Jobs::get( $other->id );
		$this->assertSame( Jobs::SENT, $other->status );
		$this->assertNotEmpty( $other->data['client_deleted'] );
	}

	private function live_post_from( string $markdown ): object {
		$job = $this->approved( $markdown );
		Sender::send( $job->id );
		return Jobs::get( $job->id );
	}

	public function test_a_correction_to_a_deleted_live_post_comes_back_as_a_draft() {
		$this->publishing();
		$job = $this->live_post();
		$this->client->posts[ $job->remote_post_id ]['status'] = 'trash';
		$job = $this->correct( $job->id, 'End.', 'The end.' );
		$new = end( $this->client->posts );
		$this->assertNotSame( (int) $new['id'], 0 );
		$this->assertSame( 'draft', $new['status'], 'a replacement, but not live' );
		$this->assertSame( Jobs::SENT, $job->status );
	}

	// 2. A typo fix keeps a post the client scheduled scheduled, with its date.
	public function test_a_correction_keeps_the_clients_schedule() {
		$this->publishing( true, 'draft' );
		$job = $this->approved();
		Sender::send( $job->id );
		$job  = Jobs::get( $job->id );
		$id   = (int) $job->remote_post_id;
		$date = gmdate( 'Y-m-d\TH:i:s', time() + WEEK_IN_SECONDS );
		$this->client->posts[ $id ]['status']   = 'future'; // the client's editor schedules it
		$this->client->posts[ $id ]['date_gmt'] = $date;
		Sender::check_site( $this->site->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( 'future', $job->remote_post_status );
		$this->assertStringStartsWith( 'Update the scheduled post', Sender::target( $job, $this->site )['label'] );

		$job = $this->correct( $job->id, 'grinder matters', 'grinder matters a lot' );
		$this->assertSame( 'future', $this->client->posts[ $id ]['status'], 'still scheduled' );
		$this->assertSame( $date, $this->client->posts[ $id ]['date_gmt'], 'same date' );
		$this->assertSame( Jobs::SENT, $job->status );
		$this->assertSame( 'future', $job->remote_post_status );
	}

	// 3. Sending never goes further than what was approved.
	public function test_a_post_approved_as_a_draft_stays_a_draft_when_settings_change_before_sending() {
		$this->publishing( true, 'draft' );
		$job = $this->approved();
		$this->assertTrue( Review::reopen( $job, $job->revision ) );
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::approve( $job, $job->markdown, $job->revision ) );
		$this->assertSame( 'draft', Jobs::get( $job->id )->data['approved_target']['status'] );
		SiteSettings::save( $this->site, null, true, false, 'publish' ); // changed while it waits in the queue
		$this->site = Sites::get( (int) $this->site->id );
		as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status );
		$this->assertSame( 'draft', $this->client->posts[ $job->remote_post_id ]['status'] );
	}

	public function test_a_post_approved_to_publish_goes_as_a_draft_if_the_site_stops_allowing_it() {
		$this->publishing();
		$job = $this->approved();
		$this->assertTrue( Review::reopen( $job, $job->revision ) );
		$job = Jobs::get( $job->id );
		$this->assertTrue( Review::approve( $job, $job->markdown, $job->revision ) );
		$this->client->can_publish = false;
		HealthCheck::check( $this->site );
		as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP );
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::SENT, $job->status, (string) $job->last_error );
		$this->assertSame( 'draft', $this->client->posts[ $job->remote_post_id ]['status'] );
	}

	// 4. Posts published before 0.6.0 (files deleted at once, no marks) can be corrected.
	public function test_posts_published_before_the_upgrade_can_be_corrected() {
		$this->publishing( false ); // 0.5.0 sent drafts
		$job = $this->approved();
		Sender::send( $job->id );
		$job = Jobs::get( $job->id );
		// As 0.5.0 left it: published by the client, files deleted, media map without alt/caption.
		$data = $job->data;
		foreach ( $data['media'] as $src => $m ) {
			unset( $data['media'][ $src ]['alt'], $data['media'][ $src ]['caption'] );
		}
		$data['published_at'] = gmdate( 'c', time() - DAY_IN_SECONDS );
		Assets::delete_for_job( $job->id );
		Jobs::update( $job->id, array( 'status' => Jobs::PUBLISHED, 'remote_post_status' => 'publish', 'data' => $data ) );
		$this->client->posts[ $job->remote_post_id ]['status'] = 'publish';
		$this->client->can_publish                            = true;
		HealthCheck::check( $this->site );

		$this->assertSame( 1, Sender::mark_legacy_deleted_files() );
		$this->assertSame( 0, Sender::mark_legacy_deleted_files(), 'once' );
		$job = $this->correct( $job->id, 'End.', 'The end.' );
		$this->assertSame( Jobs::PUBLISHED, $job->status, (string) $job->last_error );
		$this->assertCount( 2, $this->client->media, 'the client\'s images reused' );
		$this->assertStringContainsString( 'The end.', $this->client->posts[ $job->remote_post_id ]['content'] );
	}

	// 12. The editor is told a sent draft will be replaced.
	public function test_reapproving_a_sent_draft_says_it_replaces_it() {
		$job = $this->approved();
		Sender::send( $job->id );
		$target = Sender::target( Jobs::get( $job->id ), $this->site );
		$this->assertSame( 'Update the draft on Client Test', $target['label'] );
		$this->assertStringContainsString( 'replaced', $target['note'] );
	}
}
