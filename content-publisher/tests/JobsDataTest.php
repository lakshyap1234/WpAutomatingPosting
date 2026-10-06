<?php
/**
 * Jobs, assets and private storage.
 */

use CPub\Publisher\Installer;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Storage;

class JobsDataTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Installer::activate();
	}

	private function job( array $fields = array() ): object {
		$id  = Jobs::create( $fields + array( 'site_id' => 1, 'source_name' => 'post.txt', 'source_format' => 'txt', 'source_sha256' => str_repeat( 'a', 64 ) ) );
		$dir = Storage::new_job_dir( $id );
		Jobs::update( $id, array( 'data' => array( 'dir' => $dir ) ) );
		return Jobs::get( $id );
	}

	public function test_schema_v3_columns_exist() {
		global $wpdb;
		$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Installer::table( 'jobs' ) );
		foreach ( array( 'source_format', 'batch_id', 'revision', 'data', 'review_note', 'reviewed_at' ) as $c ) {
			$this->assertContains( $c, $cols );
		}
		$this->assertContains( 'origin', $wpdb->get_col( 'SHOW COLUMNS FROM ' . Installer::table( 'assets' ) ) );
	}

	public function test_create_get_and_json_columns() {
		$job = $this->job( array( 'title' => str_repeat( 'é', 300 ), 'warnings' => array( 'a', 'b' ) ) );
		$this->assertSame( Jobs::QUEUED, $job->status );
		$this->assertSame( 255, mb_strlen( $job->title ) );
		$this->assertSame( array( 'a', 'b' ), $job->warnings );
		$this->assertNull( $job->structure_map );
		$this->assertMatchesRegularExpression( '/^job-\d+-[a-f0-9]{24}$/', $job->data['dir'] );
		$this->assertSame( 2, $job->revision );
	}

	public function test_update_checks_revision_and_status() {
		$job = $this->job();
		$this->assertTrue( Jobs::update( $job->id, array( 'markdown' => '# A' ), $job->revision ) );
		$this->assertFalse( Jobs::update( $job->id, array( 'markdown' => '# B' ), $job->revision ), 'stale revision refused' );
		$this->assertSame( '# A', Jobs::get( $job->id )->markdown );
		$this->assertFalse( Jobs::update( $job->id, array( 'status' => Jobs::APPROVED ), null, array( Jobs::REVIEW ) ), 'wrong status refused' );
		$this->assertTrue( Jobs::update( $job->id, array( 'status' => Jobs::PROCESSING, 'last_error' => null ), null, array( Jobs::QUEUED ) ) );
		$this->assertSame( Jobs::PROCESSING, Jobs::get( $job->id )->status );
	}

	public function test_query_filters_and_counts() {
		$a = $this->job( array( 'title' => 'Coffee grinders', 'site_id' => 1 ) );
		$b = $this->job( array( 'title' => 'Why AI', 'site_id' => 2, 'batch_id' => str_repeat( 'b', 32 ) ) );
		Jobs::update( $b->id, array( 'status' => Jobs::REVIEW ) );
		[ $rows, $total ] = Jobs::query( array( 'status' => Jobs::REVIEW ) );
		$this->assertSame( 1, $total );
		$this->assertSame( $b->id, $rows[0]->id );
		$this->assertSame( 1, Jobs::query( array( 'search' => 'grind' ) )[1] );
		$this->assertSame( 1, Jobs::query( array( 'site_id' => 2 ) )[1] );
		$this->assertSame( 1, Jobs::query( array( 'batch_id' => str_repeat( 'b', 32 ) ) )[1] );
		$this->assertSame( 0, Jobs::query( array( 'status' => 'bogus' ) )[1] );
		$this->assertSame( 0, Jobs::query( array( 'search' => "x' OR 1=1 -- " ) )[1] );
		$counts = Jobs::counts();
		$this->assertSame( 1, $counts[ Jobs::QUEUED ] );
		$this->assertSame( 1, $counts[ Jobs::REVIEW ] );
		$this->assertSame( $a->id, Jobs::find_duplicate( 1, str_repeat( 'a', 64 ) )->id );
		$this->assertNull( Jobs::find_duplicate( 3, str_repeat( 'a', 64 ) ) );
	}

	public function test_assets_are_stored_privately_and_deleted_with_the_job() {
		$job   = $this->job();
		$png   = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		$id    = Assets::add( $job, Assets::IMAGE, 'upload', 'Hero Photo.PNG', $png, 'image/png' );
		$asset = Assets::get( $id );
		$this->assertSame( 'Hero Photo.PNG', $asset->filename );
		$this->assertMatchesRegularExpression( '#^job-\d+-[a-f0-9]{24}/[a-f0-9]{24}\.png$#', $asset->path );
		$this->assertSame( $png, Assets::bytes( $asset ) );
		$this->assertFileExists( Storage::base() . '/.htaccess' );
		$this->assertFileExists( Storage::base() . '/index.php' );
		$this->assertSame( array( 'ext' => 'png', 'mime' => 'image/png' ), Assets::sniff_image( $png ) );
		$this->assertNull( Assets::sniff_image( '<svg xmlns="http://www.w3.org/2000/svg"/>' ) );

		$abs = Storage::path( $asset->path );
		Jobs::delete( $job->id );
		$this->assertNull( Jobs::get( $job->id ) );
		$this->assertFileDoesNotExist( $abs );
		$this->assertDirectoryDoesNotExist( Storage::base() . '/' . $job->data['dir'] );
		$this->assertSame( array(), Assets::for_job( $job->id ) );
	}

	public function test_storage_paths_cannot_escape() {
		foreach ( array( '../wp-config.php', 'job-1-' . str_repeat( 'a', 24 ) . '/../../x', '/etc/passwd', 'job-1-short/x', 'job-1-' . str_repeat( 'a', 24 ) . "/x\0.png" ) as $bad ) {
			$this->assertNull( Storage::path( $bad ), $bad );
			$this->assertNull( Storage::read( $bad ), $bad );
		}
	}
}
