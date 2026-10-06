<?php

use CPub\Publisher\Capabilities;
use CPub\Publisher\Installer;

/**
 * Runs uninstall.php in a separate process so defining WP_UNINSTALL_PLUGIN
 * doesn't leak into other tests.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallTest extends WP_UnitTestCase {

	public function test_uninstall_removes_tables_caps_jobs_and_files(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Installer::activate();
		as_schedule_single_action( time() + 3600, 'cpub_process_job', array( 1 ), 'cpub' );
		as_schedule_single_action( time() + 3600, 'someone_elses_hook' );

		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/cpub-publisher/job-1';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/source.txt', 'x' );
		\CPub\Publisher\Settings\AiSettings::save( 'gemini', '', 'AIzaSyTESTtestTESTtest1234' );

		define( 'WP_UNINSTALL_PLUGIN', 'content-publisher/content-publisher.php' );
		include dirname( __DIR__ ) . '/uninstall.php';

		$this->assertCount( 5, Installer::missing_tables() );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( as_has_scheduled_action( 'cpub_process_job' ) );
		$this->assertTrue( as_has_scheduled_action( 'someone_elses_hook' ), 'Other plugins\' jobs must survive.' );
		$this->assertFalse( get_option( \CPub\Publisher\Settings\AiSettings::OPTION ), 'The encrypted AI key must go.' );
		$this->assertDirectoryDoesNotExist( $uploads['basedir'] . '/cpub-publisher' );

		Installer::activate(); // restore for later runs
	}
}
