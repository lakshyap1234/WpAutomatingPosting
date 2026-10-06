<?php

use CPub\Publisher\Capabilities;

class CapabilitiesTest extends WP_UnitTestCase {

	public function test_grant_gives_admins_both_and_editors_review_only(): void {
		Capabilities::grant();

		$admin  = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create_and_get( array( 'role' => 'author' ) );

		$this->assertTrue( $admin->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( $admin->has_cap( Capabilities::REVIEW ) );
		$this->assertFalse( $editor->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( $editor->has_cap( Capabilities::REVIEW ) );
		$this->assertFalse( $author->has_cap( Capabilities::REVIEW ) );
	}

	public function test_revoke_removes_caps_from_every_role(): void {
		Capabilities::grant();
		get_role( 'author' )->add_cap( Capabilities::REVIEW ); // granted by hand later

		Capabilities::revoke();

		foreach ( array( 'administrator', 'editor', 'author' ) as $role ) {
			$this->assertFalse( get_role( $role )->has_cap( Capabilities::MANAGE ), $role );
			$this->assertFalse( get_role( $role )->has_cap( Capabilities::REVIEW ), $role );
		}
		Capabilities::grant();
	}
}
