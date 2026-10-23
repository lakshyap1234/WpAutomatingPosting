<?php

use CPub\Publisher\Capabilities;
use CPub\Publisher\Connections\ConnectFlow;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Installer;

abstract class ConnectionsTestCase extends WP_UnitTestCase {

	protected FakeWpcom $client;
	protected int $admin;
	protected ?string $redirected = null;

	public function set_up(): void {
		parent::set_up();
		Installer::activate();
		$this->client = new FakeWpcom();
		$this->client->install();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'cpub_publisher_exit_after_redirect', '__return_false' );
		add_filter(
			'wp_redirect',
			function ( $to ) {
				$this->redirected = $to;
				return false;
			}
		);
	}

	public function tear_down(): void {
		$this->client->uninstall();
		parent::tear_down();
	}

	protected static function query_of( string $url ): array {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		return $q;
	}

	/** Start, "approve" on the fake site, and finish the callback. Returns the site row. */
	/** Connect the fake client site: start, approve on "WordPress.com", come back with the code. */
	protected function connect(): object {
		$to = ConnectFlow::start( $this->client->url, $this->admin );
		$this->assertIsString( $to, is_wp_error( $to ) ? $to->get_error_message() : '' );
		$q    = self::query_of( $to );
		$site = ConnectFlow::callback( array( 'state' => $q['state'], 'code' => $this->client->issue_code() ), $this->admin );
		$this->assertIsObject( $site, is_wp_error( $site ) ? $site->get_error_message() : '' );
		$this->assertSame( Sites::CONNECTED, $site->status );
		return $site;
	}
}
