<?php

use CPub\Publisher\Crypto\Key;

class KeyTest extends WP_UnitTestCase {

	public function test_configured_key_is_ok(): void {
		$this->assertSame( Key::OK, Key::state() );
		$this->assertSame( 32, strlen( Key::bytes() ) );
	}

	public function test_valid_key(): void {
		$this->assertSame( Key::OK, Key::state( base64_encode( random_bytes( 32 ) ) ) );
	}

	/** @dataProvider invalid_keys */
	public function test_invalid_keys( string $value ): void {
		$this->assertSame( Key::INVALID, Key::state( $value ) );
	}

	public static function invalid_keys(): array {
		return array(
			'too short'   => array( base64_encode( random_bytes( 16 ) ) ),
			'too long'    => array( base64_encode( random_bytes( 64 ) ) ),
			'not base64'  => array( 'this is not a key!' ),
			'empty'       => array( '' ),
		);
	}

	public function test_suggested_line_contains_a_valid_new_key_each_time(): void {
		$line = Key::suggested_config_line();
		$this->assertMatchesRegularExpression( "/^define\( 'CPUB_PUBLISHER_KEY', '([A-Za-z0-9+\/=]+)' \);$/", $line );
		preg_match( "/'([A-Za-z0-9+\/=]{20,})'/", $line, $m );
		$this->assertSame( Key::OK, Key::state( $m[1] ) );
		$this->assertNotSame( $line, Key::suggested_config_line() );
	}
}
