<?php
/**
 * Uploading posts, and processing them in the background.
 */

use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Installer;
use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Intake\IntakeException;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Pipeline\Llm\LlmException;
use CPub\Publisher\Pipeline\Llm\Provider;
use CPub\Publisher\Pipeline\Llm\Session;
use CPub\Publisher\Support\Events;

class IntakeAndQueueTest extends JobsTestCase {

	// ---- intake ----

	public function test_upload_creates_queued_jobs_with_their_files() {
		$r = Intake::submit( $this->site, array( self::file( '02-hardwrapped.txt' ) ), array( self::png( 'extra.png' ) ), $this->user );
		$this->assertCount( 1, $r['created'] );
		$job = Jobs::get( $r['created'][0] );
		$this->assertSame( Jobs::QUEUED, $job->status );
		$this->assertSame( '02-hardwrapped', $job->title );
		$this->assertSame( 'txt', $job->source_format );
		$this->assertSame( $this->user, $job->created_by );
		$this->assertSame( $r['batch'], $job->batch_id );
		$this->assertSame( file_get_contents( dirname( __DIR__ ) . '/samples/02-hardwrapped.txt' ), Assets::bytes( Assets::source( $job->id ) ) );
		$this->assertSame( array( 'extra.png' ), array_column( Assets::for_job( $job->id, Assets::IMAGE ), 'filename' ) ); // one post: every image goes with it
		$this->assertTrue( $this->scheduled( $job->id ) );
		$this->assertSame( 'job_uploaded', Events::for_job( $job->id )[0]->type );
	}

	public function test_images_are_shared_out_by_name_when_several_posts_are_uploaded() {
		$a = array( 'name' => 'a.txt', 'bytes' => "Post A\n\n[IMAGE: Hero.JPG]\nAlt text: x\n" );
		$b = array( 'name' => 'b.md', 'bytes' => "# Post B\n\n![y](chart.png)\n" );
		$r = Intake::submit( $this->site, array( $a, $b ), array( self::png( 'hero.png' ), self::png( 'chart.png' ), self::png( 'unused.png' ) ), $this->user );
		$this->assertSame( array( 'hero.png' ), array_column( Assets::for_job( $r['created'][0], Assets::IMAGE ), 'filename' ) ); // matched ignoring case and extension
		$this->assertSame( array( 'chart.png' ), array_column( Assets::for_job( $r['created'][1], Assets::IMAGE ), 'filename' ) );
		$this->assertSame( array( 'unused.png' ), $r['images_unused'] );
	}

	public function test_bad_files_are_skipped_with_a_reason() {
		$r       = Intake::submit(
			$this->site,
			array( self::file( '01-clean.txt' ), array( 'name' => 'evil.php', 'bytes' => '<?php' ), array( 'name' => 'empty.txt', 'bytes' => " \n" ), array( 'name' => 'big.txt', 'bytes' => str_repeat( 'a', 204801 ) ), self::file( '01-clean.txt' ) ),
			array( array( 'name' => 'x.svg', 'bytes' => '<svg xmlns="http://www.w3.org/2000/svg"/>' ), array( 'name' => 'fake.jpg', 'bytes' => 'not an image' ) ),
			$this->user
		);
		$reasons = array_column( $r['skipped'], 'reason', 'name' );
		$this->assertCount( 1, $r['created'] );
		$this->assertStringContainsString( 'Only .txt, .md and .docx', $reasons['evil.php'] );
		$this->assertStringContainsString( 'empty', $reasons['empty.txt'] );
		$this->assertStringContainsString( '200 KB', $reasons['big.txt'] );
		$this->assertStringContainsString( 'chosen twice', end( $r['skipped'] )['reason'] );
		$this->assertStringContainsString( 'Not a JPEG', $reasons['x.svg'] );
		$this->assertStringContainsString( 'Not a JPEG', $reasons['fake.jpg'] );
	}

	public function test_duplicates_are_refused_unless_asked() {
		$first = Intake::submit( $this->site, array( self::file( '01-clean.txt' ) ), array(), $this->user );
		$again = Intake::submit( $this->site, array( self::file( '01-clean.txt' ) ), array(), $this->user );
		$this->assertSame( array(), $again['created'] );
		$this->assertSame( $first['created'][0], $again['skipped'][0]['job'] );
		$this->assertStringContainsString( 'Already uploaded for this site', $again['skipped'][0]['reason'] );
		$this->assertCount( 1, Intake::submit( $this->site, array( self::file( '01-clean.txt' ) ), array(), $this->user, true )['created'] );
		// Another site may get the same file.
		$other = Sites::create( 'https://other.example', 'Other' );
		Sites::update( $other, array( 'status' => Sites::CONNECTED ) );
		$this->assertCount( 1, Intake::submit( $other, array( self::file( '01-clean.txt' ) ), array(), $this->user )['created'] );
	}

	public function test_only_connected_sites_take_uploads() {
		Sites::update( $this->site, array( 'status' => Sites::DISCONNECTED ) );
		$this->expectException( IntakeException::class );
		Intake::submit( $this->site, array( self::file( '01-clean.txt' ) ), array(), $this->user );
	}

	public function test_file_names_are_kept_but_paths_dropped() {
		$r = Intake::submit( $this->site, array( array( 'name' => "..\\..\\dir/My Post\x01.txt", 'bytes' => "Title\nBody\n" ) ), array(), $this->user );
		$this->assertSame( 'My Post.txt', Jobs::get( $r['created'][0] )->source_name );
	}

	// ---- processing ----

	public function test_processing_structures_the_post_for_review() {
		$this->provider = self::scripted( array( self::MAP_02 ) );
		$job            = $this->upload( '02-hardwrapped.txt' );
		Processor::process( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::REVIEW, $job->status );
		$this->assertSame( 'WHY OUR TEAM SWITCHED TO WEEKLY PLANNING', $job->title );
		$this->assertStringStartsWith( '# WHY OUR TEAM SWITCHED TO WEEKLY PLANNING', $job->markdown );
		$this->assertSame( 'Published 3 March 2026', $job->data['removed'][0]['text'] );
		$this->assertSame( 'WHY OUR TEAM SWITCHED TO WEEKLY PLANNING', $job->data['original']['title'] );
		$this->assertSame( 'fake · fake', $job->data['ai'] );
		$this->assertSame( 1, $job->attempts );
		$this->assertSame( self::MAP_02['title'], $job->structure_map['title'] );
		$this->assertSame( array( 'job_uploaded', 'job_processed' ), array_column( Events::for_job( $job->id ), 'type' ) );
	}

	public function test_markdown_uploads_need_no_ai() {
		$r   = Intake::submit( $this->site, array( array( 'name' => 'p.md', 'bytes' => "# Hello\n\nWorld\n" ) ), array(), $this->user );
		Processor::process( $r['created'][0] );
		$job = Jobs::get( $r['created'][0] );
		$this->assertSame( Jobs::REVIEW, $job->status );
		$this->assertNull( $job->data['ai'] );
		$this->assertSame( 'World', $job->data['original']['body_text'] );
	}

	public function test_word_files_bring_their_images_and_a_rerun_does_not_duplicate_them() {
		$bytes          = (string) file_get_contents( __DIR__ . '/fixtures/docx/coffee.docx' );
		$r              = Intake::submit( $this->site, array( array( 'name' => 'coffee.docx', 'bytes' => $bytes ) ), array(), $this->user );
		$id             = $r['created'][0];
		$map            = array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'paragraph', 'lines' => range( 2, 11 ) ) ) );
		$this->provider = self::scripted( array( self::docx_map() ) );
		Processor::process( $id );
		$job = Jobs::get( $id );
		$this->assertSame( Jobs::REVIEW, $job->status, (string) $job->last_error );
		$imgs = Assets::for_job( $id, Assets::IMAGE );
		$this->assertSame( array( 'image1.png' ), array_column( $imgs, 'filename' ) );
		$this->assertSame( 'docx', $imgs[0]->origin );
		$this->assertStringContainsString( 'Links aren’t carried over', implode( ' ', $job->warnings ) );

		$this->assertTrue( Processor::rerun( $job, $job->revision ) );
		Processor::process( $id );
		$this->assertCount( 1, Assets::for_job( $id, Assets::IMAGE ) );
	}

	public function test_temporary_ai_problems_are_retried_then_fail() {
		$this->provider = new class() implements Provider {
			public function name(): string {
				return 'busy';
			}
			public function model(): string {
				return 'busy';
			}
			public function session( string $s, string $u ): Session {
				throw new LlmException( 'Gemini error: Resource exhausted', true );
			}
		};
		$job            = $this->upload( '01-clean.txt' );
		foreach ( Processor::RETRY_DELAYS as $i => $delay ) {
			as_unschedule_all_actions( Processor::HOOK, array( $job->id ), Processor::GROUP );
			Processor::process( $job->id );
			$now = Jobs::get( $job->id );
			$this->assertSame( Jobs::QUEUED, $now->status, "retry {$i}" );
			$this->assertSame( $i + 1, $now->data['ai_retries'] );
			$this->assertStringContainsString( 'Resource exhausted', $now->last_error );
			$next = as_next_scheduled_action( Processor::HOOK, array( $job->id ), Processor::GROUP );
			$this->assertEqualsWithDelta( time() + $delay, $next, 5 );
		}
		Processor::process( $job->id );
		$this->assertSame( Jobs::FAILED, Jobs::get( $job->id )->status );
		$this->assertSame( array( 'job_uploaded', 'job_retry', 'job_retry', 'job_failed' ), array_column( Events::for_job( $job->id ), 'type' ) );
	}

	public function test_other_failures_fail_at_once_and_can_be_retried() {
		$job = $this->upload( '01-clean.txt' ); // no AI configured
		Processor::process( $job->id );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::FAILED, $job->status );
		$this->assertStringContainsString( 'No AI provider is set up', $job->last_error );

		$this->provider = self::scripted( array( self::sample_map( '01-clean' ) ) );
		$this->assertTrue( Processor::retry( $job ) );
		$this->assertTrue( $this->scheduled( $job->id ) );
		Processor::process( $job->id );
		$this->assertSame( Jobs::REVIEW, Jobs::get( $job->id )->status );
		$this->assertFalse( Processor::retry( Jobs::get( $job->id ) ), 'only failed jobs are retried' );
	}

	public function test_unexpected_errors_are_caught() {
		$this->provider = new class() implements Provider {
			public function name(): string {
				return 'x';
			}
			public function model(): string {
				return 'x';
			}
			public function session( string $s, string $u ): Session {
				throw new \Error( 'kaboom' );
			}
		};
		$job            = $this->upload( '01-clean.txt' );
		$log            = ini_set( 'error_log', '/dev/null' );
		Processor::process( $job->id );
		ini_set( 'error_log', (string) $log );
		$this->assertStringContainsString( 'Unexpected error (details are in the PHP error log): kaboom', Jobs::get( $job->id )->last_error );
	}

	public function test_one_post_at_a_time_per_site() {
		global $wpdb;
		$this->provider = self::scripted( array( self::sample_map( '01-clean' ) ) );
		$job            = $this->upload( '01-clean.txt' );
		// Another process holds this site's lock.
		$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$lock  = 'cpub_process_' . $this->site . '_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		$other->query( "SELECT GET_LOCK('{$lock}', 0)" );
		as_unschedule_all_actions( Processor::HOOK, array( $job->id ), Processor::GROUP );
		Processor::process( $job->id );
		$this->assertSame( Jobs::QUEUED, Jobs::get( $job->id )->status );
		$this->assertEqualsWithDelta( time() + Processor::BUSY_DELAY, as_next_scheduled_action( Processor::HOOK, array( $job->id ), Processor::GROUP ), 5 );
		$other->query( "SELECT RELEASE_LOCK('{$lock}')" );
		$other->close();
		Processor::process( $job->id );
		$this->assertSame( Jobs::REVIEW, Jobs::get( $job->id )->status );
	}

	public function test_cut_off_processing_is_retried_once_then_fails() {
		global $wpdb;
		$job   = $this->upload( '01-clean.txt' );
		$table = Installer::table( 'jobs' );
		$old   = gmdate( 'Y-m-d H:i:s', time() - Processor::STALE_AFTER - 60 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id = %d", $old, $job->id ) ); // phpcs:ignore
		$this->assertSame( 1, Processor::recover_stale() );
		$this->assertSame( Jobs::QUEUED, Jobs::get( $job->id )->status );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', updated_at = %s WHERE id = %d", $old, $job->id ) ); // phpcs:ignore
		Processor::recover_stale();
		$this->assertSame( Jobs::FAILED, Jobs::get( $job->id )->status );
		$this->assertSame( 0, Processor::recover_stale() );
	}

	public function test_plain_paragraphs_when_the_ai_cannot_help() {
		$job = $this->upload( '06-coffee-grinder.txt' );
		Processor::process( $job->id ); // fails: no AI
		$this->assertTrue( Processor::plain( Jobs::get( $job->id ) ) );
		$job = Jobs::get( $job->id );
		$this->assertSame( Jobs::REVIEW, $job->status, (string) $job->last_error );
		$this->assertStringContainsString( 'without the AI', $job->warnings[0] );
		$this->assertStringContainsString( '![', $job->markdown ); // image groups became images
	}

	public function test_deleted_job_is_skipped_by_the_queue() {
		$job = $this->upload( '01-clean.txt' );
		Jobs::delete( $job->id );
		Processor::process( $job->id ); // no error
		$this->assertNull( Jobs::get( $job->id ) );
	}
}
