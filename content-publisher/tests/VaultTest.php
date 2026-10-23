<?php

use CPub\Publisher\Connections\Credentials;
use CPub\Publisher\Crypto\Vault;
use CPub\Publisher\Installer;

class VaultTest extends WP_UnitTestCase {

	public function test_round_trip(): void {
		$sealed = Vault::encrypt( 'secret-token', 'site:1:access' );
		$this->assertStringStartsWith( 'v1:', $sealed );
		$this->assertStringNotContainsString( 'secret-token', $sealed );
		$this->assertSame( 'secret-token', Vault::decrypt( $sealed, 'site:1:access' ) );
	}

	public function test_same_value_encrypts_differently_each_time(): void {
		$this->assertNotSame( Vault::encrypt( 'x', 'c' ), Vault::encrypt( 'x', 'c' ) );
	}

	/** A token copied to another site's row, or from access to refresh, must not decrypt there. */
	public function test_bound_to_its_context(): void {
		$sealed = Vault::encrypt( 'secret', 'site:1:refresh' );
		$this->expectException( RuntimeException::class );
		Vault::decrypt( $sealed, 'site:2:refresh' );
	}

	public function test_tampering_is_detected(): void {
		$sealed = Vault::encrypt( 'secret', 'c' );
		$raw    = base64_decode( substr( $sealed, 3 ) );
		$raw[30] = chr( ord( $raw[30] ) ^ 1 );
		$this->expectException( RuntimeException::class );
		Vault::decrypt( 'v1:' . base64_encode( $raw ), 'c' );
	}

	public function test_credentials_are_never_stored_in_plain_text(): void {
		global $wpdb;
		Credentials::save( 5, 'wpcom_PLAINACCESS' );
		$dump = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Installer::table( 'credentials' ) ) );
		$this->assertStringNotContainsString( 'PLAINACCESS', $dump );
		$this->assertSame( 'wpcom_PLAINACCESS', Credentials::load( 5 )['access'] );
	}

	public function test_credentials_swapped_between_sites_are_refused(): void {
		global $wpdb;
		Credentials::save( 5, 'a5' );
		Credentials::save( 6, 'a6' );
		$t  = Installer::table( 'credentials' );
		$a5 = $wpdb->get_var( "SELECT access_token FROM $t WHERE site_id = 5" );
		$wpdb->update( $t, array( 'access_token' => $a5 ), array( 'site_id' => 6 ) );
		$this->expectException( RuntimeException::class );
		Credentials::load( 6 );
	}
}
