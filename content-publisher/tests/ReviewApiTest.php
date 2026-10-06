<?php
/**
 * The review editor's REST API and the image file handler.
 */

use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Jobs\AssetController;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;

class ReviewApiTest extends JobsTestCase {

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	private function call( string $method, string $path, array $params = array() ): WP_REST_Response {
		$req = new WP_REST_Request( $method, '/cpub/v1' . $path );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_do_request( $req );
	}

	/** A job ready for review, with sample 06 (three images given by web address). */
	private function reviewable( string $sample = '02-hardwrapped.txt', ?array $map = null, array $images = array() ): object {
		$this->provider = self::scripted( array( $map ?? self::MAP_02 ) );
		$r              = Intake::submit( $this->site, array( self::file( $sample ) ), $images, $this->user );
		Processor::process( $r['created'][0] );
		$job = Jobs::get( $r['created'][0] );
		$this->assertSame( Jobs::REVIEW, $job->status, (string) $job->last_error );
		return $job;
	}

	public function test_only_reviewers_get_in() {
		$job = $this->reviewable();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->call( 'GET', "/jobs/{$job->id}" )->get_status() );
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', "/jobs/{$job->id}" )->get_status() );
		$this->assertSame( 401, $this->call( 'POST', "/jobs/{$job->id}/approve", array( 'markdown' => 'x', 'revision' => 1 ) )->get_status() );
	}

	public function test_view_has_what_the_editor_needs() {
		$job = $this->reviewable();
		$v   = $this->call( 'GET', "/jobs/{$job->id}" )->get_data();
		$this->assertSame( 'review', $v['status'] );
		$this->assertSame( 'Ready for review', $v['status_text'] );
		$this->assertSame( 'Client', $v['site']['name'] );
		$this->assertStringStartsWith( '# WHY OUR TEAM', $v['markdown'] );
		$this->assertSame( 'Published 3 March 2026', $v['removed'][0]['text'] );
		$this->assertSame( 'Published 3 March 2026', $v['removed'][0]['markdown'] );
		$this->assertNotEmpty( $v['original']['body_text'] );
		$this->assertTrue( $v['can']['approve'] );
		$this->assertFalse( $v['can']['reopen'] );
		$this->assertSame( array( 'job_uploaded', 'job_processed' ), array_column( $v['history'], 'type' ) );
		$this->assertSame( 404, $this->call( 'GET', '/jobs/999999' )->get_status() );
	}

	public function test_images_given_by_file_name_use_the_uploaded_files() {
		$src = "Title\n\n[IMAGE: Hero.jpg]\nAlt text: A cup\n\nBody text.\n";
		$map = array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'image', 'lines' => array( 2, 3 ) ), array( 'type' => 'paragraph', 'lines' => array( 4 ) ) ) );
		$this->provider = self::scripted( array( $map ) );
		$r   = Intake::submit( $this->site, array( array( 'name' => 'p.txt', 'bytes' => $src ) ), array(), $this->user );
		Processor::process( $r['created'][0] );
		$job = Jobs::get( $r['created'][0] );

		$p = $this->call( 'POST', "/jobs/{$job->id}/preview", array( 'markdown' => $job->markdown ) )->get_data();
		$this->assertStringContainsString( 'uploaded with the post', $p['errors'][0]['message'] );

		$v = $this->call( 'GET', "/jobs/{$job->id}" )->get_data();
		$this->assertMatchesRegularExpression( '/"Hero\.jpg" on line 2 has no Image URL/', implode( ' ', $v['warnings'] ) );
		// Upload it from the editor under a slightly different name: still matched.
		$v = \CPub\Publisher\Rest\JobsController::add_image_bytes( $job, 'hero.png', base64_decode( self::PNG ) )->get_data();
		$this->assertSame( 'hero.png', $v['images'][0]['filename'] );
		$this->assertTrue( $v['images'][0]['used'] );
		$this->assertSame( array(), $v['warnings'], 'the note about the missing file is gone' );
		$p = $this->call( 'POST', "/jobs/{$job->id}/preview", array( 'markdown' => $job->markdown ) )->get_data();
		$this->assertSame( array(), $p['errors'] );
		$this->assertStringContainsString( 'admin-post.php?action=cpub_asset', html_entity_decode( $p['html'] ) );
		$this->assertTrue( $p['images'][0]['file'] );

		// Same name again replaces it; delete removes it.
		$v = \CPub\Publisher\Rest\JobsController::add_image_bytes( Jobs::get( $job->id ), 'HERO.png', base64_decode( self::PNG ) )->get_data();
		$this->assertCount( 1, $v['images'] );
		$bad = \CPub\Publisher\Rest\JobsController::add_image_bytes( Jobs::get( $job->id ), 'x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>' );
		$this->assertSame( 415, $bad->get_error_data()['status'] );
		$v = $this->call( 'DELETE', "/jobs/{$job->id}/images/{$v['images'][0]['id']}" )->get_data();
		$this->assertSame( array(), $v['images'] );
	}

	public function test_word_images_are_matched_automatically() {
		$bytes          = (string) file_get_contents( __DIR__ . '/fixtures/docx/coffee.docx' );
		$this->provider = self::scripted( array( self::docx_map() ) );
		$r              = Intake::submit( $this->site, array( array( 'name' => 'coffee.docx', 'bytes' => $bytes ) ), array(), $this->user );
		Processor::process( $r['created'][0] );
		$job = Jobs::get( $r['created'][0] );
		$p   = $this->call( 'POST', "/jobs/{$job->id}/preview", array( 'markdown' => $job->markdown ) )->get_data();
		$this->assertSame( array(), $p['errors'] );
		$this->assertSame( 1, $p['counts']['images'] );
	}

	public function test_save_detects_conflicts() {
		$job = $this->reviewable();
		$md  = $job->markdown . "\nAdded.\n";
		$v   = $this->call( 'PUT', "/jobs/{$job->id}", array( 'markdown' => $md, 'revision' => $job->revision ) )->get_data();
		$this->assertSame( $md, $v['markdown'] );
		$this->assertGreaterThan( $job->revision, $v['revision'] );
		// Someone else saved meanwhile: the stale copy is refused.
		$res = $this->call( 'PUT', "/jobs/{$job->id}", array( 'markdown' => $md . "More.\n", 'revision' => $job->revision ) );
		$this->assertSame( 409, $res->get_status() );
		$this->assertStringContainsString( 'changed', $res->get_data()['message'] );
		$this->assertSame( $md, Jobs::get( $job->id )->markdown );
		// Saving the same text again is fine at the current revision.
		$this->assertSame( 200, $this->call( 'PUT', "/jobs/{$job->id}", array( 'markdown' => $md, 'revision' => $v['revision'] ) )->get_status() );
		// The title follows the Markdown.
		$v = $this->call( 'PUT', "/jobs/{$job->id}", array( 'markdown' => "# New title\n\nBody\n", 'revision' => $v['revision'] ) )->get_data();
		$this->assertSame( 'New title', $v['title'] );
	}

	public function test_approve_only_without_problems_then_reopen() {
		$job = $this->reviewable();
		$res = $this->call( 'POST', "/jobs/{$job->id}/approve", array( 'markdown' => "# T\n\n> quote\n", 'revision' => $job->revision ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertStringContainsString( 'Quotes', $res->get_data()['data']['problems'][0]['message'] );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::REVIEW, $job->status );

		$v = $this->call( 'POST', "/jobs/{$job->id}/approve", array( 'markdown' => "# T\n\nFine.\n", 'revision' => $job->revision ) )->get_data();
		$this->assertSame( 'approved', $v['status'] );
		$this->assertSame( wp_get_current_user()->display_name, $v['reviewed_by'] );
		$this->assertFalse( $v['can']['edit'] );
		$this->assertFalse( $v['can']['delete'] );
		$this->assertSame( 409, $this->call( 'PUT', "/jobs/{$job->id}", array( 'markdown' => "# X\n\nY\n", 'revision' => $v['revision'] ) )->get_status() );
		$this->assertSame( 409, $this->call( 'DELETE', "/jobs/{$job->id}" )->get_status() );

		$v = $this->call( 'POST', "/jobs/{$job->id}/reopen", array( 'revision' => $v['revision'] ) )->get_data();
		$this->assertSame( 'review', $v['status'] );
		$this->assertContains( 'job_approved', array_column( $v['history'], 'type' ) );
		$this->assertContains( 'job_reopened', array_column( $v['history'], 'type' ) );
	}

	public function test_reject_needs_a_reason() {
		$job = $this->reviewable();
		$this->assertSame( 400, $this->call( 'POST', "/jobs/{$job->id}/reject", array( 'reason' => '  ', 'revision' => $job->revision ) )->get_status() );
		$v = $this->call( 'POST', "/jobs/{$job->id}/reject", array( 'reason' => 'Wrong client <b>file</b>', 'revision' => $job->revision ) )->get_data();
		$this->assertSame( 'rejected', $v['status'] );
		$this->assertSame( 'Wrong client file', $v['review_note'] );
		$this->assertTrue( $v['can']['reopen'] );
	}

	public function test_rerun_and_delete() {
		$job = $this->reviewable();
		$v   = $this->call( 'POST', "/jobs/{$job->id}/rerun", array( 'revision' => $job->revision ) )->get_data();
		$this->assertSame( 'queued', $v['status'] );
		$this->assertSame( 409, $this->call( 'POST', "/jobs/{$job->id}/rerun", array( 'revision' => $v['revision'] ) )->get_status(), 'already queued' );
		$this->assertTrue( as_has_scheduled_action( Processor::HOOK, array( $job->id ), Processor::GROUP ) );
		$this->assertSame( array( 'deleted' => true ), $this->call( 'DELETE', "/jobs/{$job->id}" )->get_data() );
		$this->assertNull( Jobs::get( $job->id ) );
	}

	public function test_statuses_for_the_queue_page() {
		$job = $this->reviewable();
		$d   = $this->call( 'GET', '/jobs/status', array( 'ids' => "{$job->id},999999,abc" ) )->get_data();
		$this->assertSame( 'review', $d[ $job->id ]['status'] );
		$this->assertNull( $d[999999] );
	}

	// ---- image files ----

	public function test_image_files_are_served_only_to_reviewers_with_a_valid_link() {
		$job   = $this->reviewable( '02-hardwrapped.txt', null, array( self::png( 'a.png' ) ) );
		$asset = Assets::for_job( $job->id, Assets::IMAGE )[0];
		$sent  = null;
		add_filter(
			'cpub_publisher_asset_send',
			function ( $go, $headers, $bytes ) use ( &$sent ) {
				$sent = array( $headers, $bytes );
				return false;
			},
			10,
			3
		);
		parse_str( (string) wp_parse_url( AssetController::url( $job->id, (int) $asset->id ), PHP_URL_QUERY ), $q );
		$_GET = $_REQUEST = $q; // phpcs:ignore
		AssetController::serve();
		$this->assertSame( base64_decode( self::PNG ), $sent[1] );
		$this->assertSame( 'image/png', $sent[0]['Content-Type'] );
		$this->assertSame( 'nosniff', $sent[0]['X-Content-Type-Options'] );

		foreach (
			array(
				'bad nonce'     => array( '_wpnonce' => 'x' ) + $q,
				'other job'     => array( 'job' => $job->id + 1 ) + $q,
			) as $label => $query
		) {
			$_GET = $_REQUEST = $query; // phpcs:ignore
			try {
				AssetController::serve();
				$this->fail( $label );
			} catch ( WPDieException $e ) {
				$this->assertTrue( true );
			}
		}
		$other_job = Jobs::create( array( 'site_id' => $this->site, 'source_name' => 'x.txt' ) );
		$_GET      = $_REQUEST = array( 'job' => $other_job, 'asset' => $asset->id, '_wpnonce' => wp_create_nonce( AssetController::ACTION . '_' . $other_job ) ); // phpcs:ignore
		try {
			AssetController::serve();
			$this->fail( 'asset of another job' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'Not found', $e->getMessage() );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_GET = $_REQUEST = $q; // phpcs:ignore
		$this->expectException( WPDieException::class );
		AssetController::serve();
	}
}
