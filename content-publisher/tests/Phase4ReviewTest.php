<?php
/**
 * Regression tests for the Phase 4 review findings.
 */

use CPub\Publisher\Installer;
use CPub\Publisher\Intake\Docx;
use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Intake\IntakeException;
use CPub\Publisher\Intake\ZipReader;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Jobs\Review;
use CPub\Publisher\Jobs\Storage;
use CPub\Publisher\Support\Events;

class Phase4ReviewTest extends JobsTestCase {

	public function tear_down() {
		ZipReader::$force_builtin = false;
		parent::tear_down();
	}

	// 1. Stored files never get an extension a server could run.
	public function test_uploaded_names_cannot_choose_the_stored_extension() {
		$png   = base64_decode( self::PNG ) . '<?php echo "EXECUTED"; ?>';
		$r     = Intake::submit( $this->site, array( self::file( '01-clean.txt' ) ), array( array( 'name' => 'shell.php', 'bytes' => $png ), array( 'name' => 'x.phtml', 'bytes' => $png ), array( 'name' => 'shot.screenshot', 'bytes' => $png ) ), $this->user );
		$paths = array_column( Assets::for_job( $r['created'][0] ), 'path', 'filename' );
		$this->assertStringEndsWith( '.txt', $paths['01-clean.txt'] );
		foreach ( array( 'shell.php', 'x.phtml', 'shot.screenshot' ) as $n ) {
			$this->assertStringEndsWith( '.png', $paths[ $n ], $n );
		}
		$job = Jobs::get( $r['created'][0] );
		$a   = Review::add_image( $job, 'editor.php', $png );
		$this->assertStringEndsWith( '.png', $a->path );
		foreach ( array( 'php', 'phtml', 'phar', 'php7', 'htaccess', 'svg', 'html' ) as $ext ) {
			$this->assertMatchesRegularExpression( '#/[a-f0-9]{24}$#', Storage::put( $job->data['dir'], $ext, 'x' ), $ext );
		}
	}

	// 2. A failure half-way through an upload leaves nothing behind.
	public function test_a_storage_failure_leaves_no_stuck_job() {
		$n = 0;
		add_filter(
			'query',
			function ( $q ) use ( &$n ) {
				return str_contains( $q, Installer::table( 'assets' ) ) && str_starts_with( $q, 'INSERT' ) && 2 === ++$n ? 'SELECT * FROM no_such_table_for_the_test' : $q;
			}
		);
		global $wpdb;
		$log  = ini_set( 'error_log', '/dev/null' );
		$prev = $wpdb->suppress_errors( true );
		$r    = Intake::submit( $this->site, array( self::file( '01-clean.txt' ) ), array( self::png( 'a.png' ) ), $this->user );
		$wpdb->suppress_errors( $prev );
		ini_set( 'error_log', (string) $log );
		$this->assertSame( array(), $r['created'] );
		$this->assertStringContainsString( 'Could not be stored', $r['skipped'][0]['reason'] );
		$this->assertSame( 0, array_sum( Jobs::counts() ) );
		// …so the same file can be uploaded again.
		remove_all_filters( 'query' );
		$this->assertCount( 1, Intake::submit( $this->site, array( self::file( '01-clean.txt' ) ), array(), $this->user )['created'] );
	}

	public function test_a_queued_job_that_lost_its_action_is_queued_again() {
		global $wpdb;
		$job = $this->upload( '01-clean.txt' );
		as_unschedule_all_actions( Processor::HOOK, array( $job->id ), Processor::GROUP );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Installer::table( 'jobs' ) . ' SET updated_at = %s WHERE id = %d', gmdate( 'Y-m-d H:i:s', time() - Processor::STALE_AFTER - 60 ), $job->id ) ); // phpcs:ignore
		Processor::recover_stale();
		$this->assertTrue( $this->scheduled( $job->id ) );
	}

	// 5. Without ZipArchive, a part that lies about its size can't exhaust memory.
	public function test_builtin_zip_reader_stops_at_the_limit_even_when_sizes_lie() {
		$tmp = wp_tempnam( 'bomb' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );
		$zip->addFromString( 'word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . str_repeat( '<w:p/>', 20000000 ) . '</w:body></w:document>' );
		$zip->close();
		$bytes = (string) file_get_contents( $tmp );
		unlink( $tmp );
		// Declare the part as 1000 bytes in both headers.
		$bytes = preg_replace_callback( '/PK\x01\x02.{20}\K.{4}/s', fn() => pack( 'V', 1000 ), $bytes, 1 );
		$bytes = preg_replace_callback( '/PK\x03\x04.{18}\K.{4}/s', fn() => pack( 'V', 1000 ), $bytes, 1 );
		$this->assertLessThan( 200000, strlen( $bytes ) );
		ZipReader::$force_builtin = true;
		memory_reset_peak_usage(); // building the file above used a lot; measure only the read
		$before                   = memory_get_usage();
		try {
			Docx::read( $bytes );
			$this->fail( 'expected refusal' );
		} catch ( IntakeException $e ) {
			$this->assertStringContainsString( 'too large', $e->getMessage() );
		}
		$this->assertLessThan( 80 * 1048576, memory_get_peak_usage() - $before ); // the part inflates to 120 MB
	}

	public function test_builtin_zip_reader_refuses_garbage() {
		ZipReader::$force_builtin = true;
		foreach ( array( "PK\x03\x04garbage", "PK\x03\x04" . str_repeat( 'x', 100 ) . "PK\x05\x06" . str_repeat( "\xff", 18 ) ) as $bad ) {
			try {
				Docx::read( $bad );
				$this->fail( 'expected refusal' );
			} catch ( IntakeException $e ) {
				$this->assertStringContainsString( 'valid Word', $e->getMessage() );
			}
		}
	}

	// 6. An image change is a new revision: an approval based on the old one is refused.
	public function test_image_changes_need_a_fresh_approval() {
		$this->provider = self::scripted( array( self::MAP_02 ) );
		$job            = $this->upload( '02-hardwrapped.txt' );
		Processor::process( $job->id );
		$job = Jobs::get( $job->id );
		Review::add_image( $job, 'late.png', base64_decode( self::PNG ) );
		$r = Review::approve( Jobs::get( $job->id ), $job->markdown, $job->revision );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertSame( 'cpub_conflict', $r->get_error_code() );
	}

	// 8. A doctype is refused wherever it is.
	public function test_doctype_after_a_long_comment_is_refused() {
		$xml = '<?xml version="1.0"?><!--' . str_repeat( 'x', 5000 ) . '--><!DOCTYPE d [<!ENTITY e "INTERNAL">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&e;</w:t></w:r></w:p></w:body></w:document>';
		$tmp = wp_tempnam( 'dt' );
		$zip = new ZipArchive();
		$zip->open( $tmp, ZipArchive::OVERWRITE );
		$zip->addFromString( 'word/document.xml', $xml );
		$zip->close();
		$bytes = (string) file_get_contents( $tmp );
		unlink( $tmp );
		$this->expectException( IntakeException::class );
		$this->expectExceptionMessage( 'document type declaration' );
		Docx::read( $bytes );
	}

	// 9. The editor's size limit keeps the parser's memory bounded.
	public function test_markdown_size_limit() {
		$this->provider = self::scripted( array( self::MAP_02 ) );
		$job            = $this->upload( '02-hardwrapped.txt' );
		Processor::process( $job->id );
		$job = Jobs::get( $job->id );
		$r   = Review::save( $job, "# T\n\n" . str_repeat( 'a', 262144 ), $job->revision ); // 256 KB + a title
		$this->assertSame( 'cpub_too_long', $r->get_error_code() );
	}

	// Minor: history shows the newest events; delete is atomic; a replaced Word image survives a rerun.
	public function test_history_shows_the_newest_events() {
		$job = $this->upload( '01-clean.txt' );
		for ( $i = 0; $i < 205; $i++ ) {
			Events::log( 'x', "event {$i}", $this->site, $job->id );
		}
		$h = Events::for_job( $job->id );
		$this->assertCount( 200, $h );
		$this->assertSame( 'event 204', end( $h )->message );
	}

	public function test_delete_refuses_a_job_that_started_processing_meanwhile() {
		$job = $this->upload( '01-clean.txt' ); // read while queued
		Jobs::update( $job->id, array( 'status' => Jobs::PROCESSING ) );
		$r = Review::delete( $job );
		$this->assertInstanceOf( WP_Error::class, $r );
		$this->assertNotNull( Jobs::get( $job->id ) );
	}

	public function test_a_reviewers_replacement_of_a_word_image_survives_a_rerun() {
		$bytes          = (string) file_get_contents( __DIR__ . '/fixtures/docx/coffee.docx' );
		$this->provider = self::scripted( array( self::docx_map() ) );
		$id             = Intake::submit( $this->site, array( array( 'name' => 'coffee.docx', 'bytes' => $bytes ) ), array(), $this->user )['created'][0];
		Processor::process( $id );
		$job = Jobs::get( $id );
		Review::add_image( $job, 'image1.png', base64_decode( self::PNG ) );
		$job = Jobs::get( $id );
		Processor::rerun( $job, $job->revision );
		Processor::process( $id );
		$imgs = Assets::for_job( $id, Assets::IMAGE );
		$this->assertSame( array( array( 'image1.png', 'editor' ) ), array_map( fn( $a ) => array( $a->filename, $a->origin ), $imgs ) );
	}
}
