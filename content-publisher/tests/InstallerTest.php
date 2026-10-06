<?php

use CPub\Publisher\Capabilities;
use CPub\Publisher\Installer;

/**
 * The WP test case turns CREATE TABLE into CREATE TEMPORARY TABLE, which
 * SHOW TABLES doesn't list. These tests need real tables, so they switch that
 * off and clean up after themselves.
 */
class InstallerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Installer::drop_tables();
	}

	public function tear_down(): void {
		Installer::install(); // Leave the tables in place for other tests.
		parent::tear_down();
	}

	public function test_activation_creates_all_tables_and_records_schema_version(): void {
		$this->assertCount( 5, Installer::missing_tables() );

		Installer::activate();

		$this->assertSame( array(), Installer::missing_tables() );
		$this->assertSame( Installer::SCHEMA_VERSION, (int) get_option( Installer::SCHEMA_OPTION ) );
	}

	public function test_install_is_idempotent(): void {
		Installer::install();
		Installer::install();
		$this->assertSame( array(), Installer::missing_tables() );
	}

	public function test_tables_have_expected_columns(): void {
		global $wpdb;
		Installer::install();
		$columns = $wpdb->get_col( 'DESCRIBE ' . Installer::table( 'jobs' ) );
		foreach ( array( 'id', 'site_id', 'status', 'markdown', 'structure_map', 'remote_post_id', 'created_by', 'reviewed_by' ) as $col ) {
			$this->assertContains( $col, $columns );
		}
		$columns = $wpdb->get_col( 'DESCRIBE ' . Installer::table( 'credentials' ) );
		$this->assertContains( 'refresh_token', $columns );
	}

	public function test_maybe_upgrade_installs_when_schema_is_older(): void {
		update_option( Installer::SCHEMA_OPTION, 0 );
		Installer::maybe_upgrade();
		$this->assertSame( array(), Installer::missing_tables() );
	}

	public function test_maybe_upgrade_does_nothing_when_current(): void {
		update_option( Installer::SCHEMA_OPTION, Installer::SCHEMA_VERSION );
		Installer::maybe_upgrade();
		$this->assertCount( 5, Installer::missing_tables(), 'Should not reinstall when the schema version is current.' );
	}

	public function test_drop_tables_removes_tables_and_option(): void {
		Installer::install();
		Installer::drop_tables();
		$this->assertCount( 5, Installer::missing_tables() );
		$this->assertFalse( get_option( Installer::SCHEMA_OPTION ) );
	}

	public function test_only_one_request_upgrades_at_a_time(): void {
		global $wpdb;
		Installer::activate();
		update_option( Installer::SCHEMA_OPTION, 2, false );
		$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$lock  = 'cpub_upgrade_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		$other->query( "SELECT GET_LOCK('{$lock}', 0)" );
		Installer::maybe_upgrade();
		$this->assertSame( 2, (int) get_option( Installer::SCHEMA_OPTION ), 'busy: left to the other request' );
		$other->query( "SELECT RELEASE_LOCK('{$lock}')" );
		$other->close();
		Installer::maybe_upgrade();
		$this->assertSame( Installer::SCHEMA_VERSION, (int) get_option( Installer::SCHEMA_OPTION ) );
	}
}
