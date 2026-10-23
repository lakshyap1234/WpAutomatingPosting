<?php

use CPub\Publisher\Connections\ConnectFlow;
use CPub\Publisher\Connections\Connections;
use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Connections\HealthCheck;
use CPub\Publisher\Connections\Sites;

/**
 * Regression tests for the independent review of Phase 2. (The token renewal,
 * issuer and discovery checks went with the Connector: WordPress.com tokens
 * don't rotate, and there is one authorization server.)
 */
class Phase2ReviewTest extends ConnectionsTestCase {

	public function test_disconnect_waits_for_whoever_holds_the_site_lock(): void {
		global $wpdb;
		$site  = $this->connect();
		$other = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		$lock  = 'cpub_refresh_' . (int) $site->id . '_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		$other->query( "SELECT GET_LOCK('$lock', 0)" );
		add_filter( 'query', $shorten = fn( $q ) => str_replace( ', 20)', ', 1)', $q ) );
		$res = Connections::disconnect( $site );
		remove_filter( 'query', $shorten );
		$other->query( "SELECT RELEASE_LOCK('$lock')" );
		$other->close();
		$this->assertSame( 'cpub_busy', $res->get_error_code() );
		$this->assertSame( Sites::CONNECTED, Sites::get( (int) $site->id )->status, 'Nothing changed while the lock was held.' );
		$this->assertNotNull( Credentials::load( (int) $site->id ) );
	}

	public function test_daily_run_queues_one_spaced_check_per_site(): void {
		$a = $this->connect();
		$b = Sites::create( 'https://second.test', 'Second' );
		Sites::update( $b, array( 'status' => Sites::CONNECTED ) );
		HealthCheck::run_all();
		HealthCheck::run_all(); // not doubled
		$ids = as_get_scheduled_actions( array( 'hook' => HealthCheck::SITE_HOOK, 'status' => 'pending' ), 'ids' );
		$this->assertCount( 2, $ids );
		$times = array_map( fn( $id ) => as_get_datetime_object( ActionScheduler::store()->get_date( $id ) )->getTimestamp(), $ids );
		$this->assertGreaterThanOrEqual( HealthCheck::SPACING, abs( $times[0] - $times[1] ) );
	}

	public function test_overlong_remote_strings_are_cut_to_fit(): void {
		$this->client->site_name = str_repeat( 'N', 500 );
		$this->client->author    = str_repeat( 'A', 500 );
		$site                    = $this->connect();
		$this->assertSame( 190, mb_strlen( $site->name ) );
		$this->assertSame( 190, mb_strlen( $site->remote_user ) );
		$this->assertSame( $this->client->site_id, (int) $site->wpcom_site_id, 'the row was still saved' );
	}

	public function test_a_callback_without_a_state_sends_the_code_nowhere(): void {
		ConnectFlow::start( $this->client->url, $this->admin );
		$calls = count( $this->client->calls );
		$err   = ConnectFlow::callback( array( 'code' => 'x' ), $this->admin );
		$this->assertSame( 'cpub_state', $err->get_error_code() );
		$this->assertCount( $calls, $this->client->calls );
	}
}
