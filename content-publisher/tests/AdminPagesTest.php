<?php
/**
 * Posts list, Add posts, editor host page, per-site settings, menu.
 */

use CPub\Publisher\Admin\AddPostsPage;
use CPub\Publisher\Admin\EditorPage;
use CPub\Publisher\Admin\Menu;
use CPub\Publisher\Admin\PostsPage;
use CPub\Publisher\Admin\SiteSettingsPage;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\SiteSettings;
use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;

class AdminPagesTest extends ConnectionsTestCase {

	public function tear_down(): void {
		$_GET = $_POST = $_REQUEST = array(); // phpcs:ignore
		parent::tear_down();
	}

	private function render( object $page ): string {
		ob_start();
		$page->render();
		return (string) ob_get_clean();
	}

	private function job( object $site, string $name = 'p.txt', string $text = "Title\nBody\n" ): object {
		return Jobs::get( Intake::submit( (int) $site->id, array( array( 'name' => $name, 'bytes' => $text ) ), array(), $this->admin )['created'][0] );
	}

	public function test_posts_list_shows_jobs_escaped_with_the_right_actions() {
		$site   = $this->connect();
		$queued = $this->job( $site, '<img src=x onerror=alert(1)>.txt' );
		$failed = $this->job( $site, 'f.txt', "Other\nText\n" );
		Processor::process( $failed->id ); // no AI: fails
		$_GET = array( 'page' => PostsPage::SLUG ); // phpcs:ignore
		$html = $this->render( new PostsPage() );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'Failed', $html );
		$this->assertStringContainsString( 'No AI provider is set up', $html );
		$this->assertStringContainsString( 'cpub_job_retry', $html );
		$this->assertStringContainsString( 'Set out without the AI', $html );
		$this->assertStringContainsString( 'This page updates itself', $html ); // one post still queued
		$this->assertStringContainsString( "url.indexOf( '?' ) < 0 ? '?' : '&'", $html ); // works with plain permalinks (?rest_route=)
		$this->assertMatchesRegularExpression( '/Queued <span class="count">\(1\)<\/span>/', $html );

		$_GET = array( 'page' => PostsPage::SLUG, 'status' => 'failed' ); // phpcs:ignore
		$html = $this->render( new PostsPage() );
		$this->assertStringContainsString( 'f.txt', $html );
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'updates itself', $html );
	}

	public function test_row_actions_need_permission_and_nonce() {
		$site = $this->connect();
		$job  = $this->job( $site );
		Processor::process( $job->id );
		$this->assertSame( Jobs::FAILED, Jobs::get( $job->id )->status );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_REQUEST = $_GET = array( 'action' => 'cpub_job_delete', 'job' => $job->id, '_wpnonce' => wp_create_nonce( 'cpub_job_delete_' . $job->id ) ); // phpcs:ignore
		try {
			PostsPage::handle();
			$this->fail( 'author refused' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( Jobs::get( $job->id ) );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$_REQUEST['_wpnonce'] = 'forged'; // phpcs:ignore
		try {
			PostsPage::handle();
			$this->fail( 'forged nonce refused' );
		} catch ( WPDieException $e ) {
			$this->assertNotNull( Jobs::get( $job->id ) );
		}
		$_REQUEST = array( 'action' => 'cpub_job_retry', 'job' => $job->id, '_wpnonce' => wp_create_nonce( 'cpub_job_retry_' . $job->id ) ); // phpcs:ignore
		PostsPage::handle();
		$this->assertSame( Jobs::QUEUED, Jobs::get( $job->id )->status );
		$_REQUEST = array( 'action' => 'cpub_job_delete', 'job' => $job->id, '_wpnonce' => wp_create_nonce( 'cpub_job_delete_' . $job->id ) ); // phpcs:ignore
		PostsPage::handle();
		$this->assertNull( Jobs::get( $job->id ) );
	}

	public function test_add_posts_needs_permission_and_nonce_and_a_file() {
		$site = $this->connect();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$_POST = $_REQUEST = array( 'site_id' => $site->id, '_wpnonce' => wp_create_nonce( AddPostsPage::ACTION ) ); // phpcs:ignore
		try {
			AddPostsPage::handle();
			$this->fail( 'author refused' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( true );
		}
		wp_set_current_user( $this->admin );
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = 'forged'; // phpcs:ignore
		try {
			AddPostsPage::handle();
			$this->fail( 'forged nonce refused' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( true );
		}
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( AddPostsPage::ACTION ); // phpcs:ignore
		AddPostsPage::handle();
		$this->assertStringContainsString( 'page=' . AddPostsPage::SLUG, (string) $this->redirected );
		$this->assertSame( 0, array_sum( Jobs::counts() ) );
	}

	public function test_add_posts_page_lists_connected_sites_only() {
		$site = $this->connect();
		$gone = Sites::create( 'https://gone.example', 'Gone' );
		$html = $this->render( new AddPostsPage() );
		$this->assertStringContainsString( 'value="' . $site->id . '"', $html );
		$this->assertStringNotContainsString( 'gone.example', $html );
		$this->assertStringContainsString( 'name="posts[]"', $html );
		$this->assertStringContainsString( 'name="images[]"', $html );
	}

	public function test_editor_page_handles_missing_job_and_missing_build() {
		$_GET = array( 'page' => EditorPage::SLUG, 'job' => 999999 ); // phpcs:ignore
		$this->assertStringContainsString( 'Post not found', $this->render( new EditorPage() ) );
		$site = $this->connect();
		$job  = $this->job( $site );
		$_GET = array( 'page' => EditorPage::SLUG, 'job' => $job->id ); // phpcs:ignore
		EditorPage::enqueue();
		$html = $this->render( new EditorPage() );
		if ( is_file( dirname( __DIR__ ) . '/build/editor.asset.php' ) ) {
			$this->assertStringContainsString( 'id="cpub-editor-root"', $html );
			$this->assertStringContainsString( '"jobId":' . $job->id, wp_scripts()->get_data( EditorPage::HANDLE, 'before' )[1] );
		} else {
			$this->assertStringContainsString( 'editor files are missing', $html );
		}
	}

	public function test_site_settings_use_the_client_categories() {
		$site = $this->connect();
		$_GET = array( 'site' => $site->id ); // phpcs:ignore
		$html = $this->render( new SiteSettingsPage() );
		$this->assertStringContainsString( 'Coffee &amp; Tea', $html ); // decoded from the API, escaped once here
		$this->assertStringContainsString( '— Espresso', $html );

		$_POST = $_REQUEST = array( 'site' => $site->id, 'default_category' => 6, 'use_post_category' => '1', '_wpnonce' => wp_create_nonce( SiteSettingsPage::ACTION . '_' . $site->id ) ); // phpcs:ignore
		SiteSettingsPage::save();
		$s = SiteSettings::get( Sites::get( (int) $site->id ) );
		$this->assertSame( array( 'id' => 6, 'name' => 'Espresso' ), $s['default_category'] );
		$this->assertTrue( $s['use_post_category'] );

		// A category that isn't on the site is refused; the setting stays.
		$_POST['default_category'] = $_REQUEST['default_category'] = 42; // phpcs:ignore
		SiteSettingsPage::save();
		$this->assertSame( 6, SiteSettings::get( Sites::get( (int) $site->id ) )['default_category']['id'] );

		// Back to the site's own default.
		$_POST['default_category'] = 0;
		unset( $_POST['use_post_category'] );
		SiteSettingsPage::save();
		$this->assertSame( array( 'default_category' => null, 'use_post_category' => false, 'featured_image' => false, 'send_as' => 'draft' ), SiteSettings::get( Sites::get( (int) $site->id ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		SiteSettingsPage::save();
	}

	public function test_categories_are_read_page_by_page() {
		$site                     = $this->connect();
		$this->client->categories = array_map( fn( $i ) => array( 'id' => $i, 'name' => "Cat {$i}", 'parent' => 0 ), range( 1, 230 ) );
		$this->assertCount( 230, SiteSettings::categories( $site ) );
		$this->client->offline = true;
		$this->assertInstanceOf( WP_Error::class, SiteSettings::categories( $site ) );
	}

	public function test_menu_puts_posts_first_and_hides_the_editor() {
		global $submenu, $_registered_pages;
		wp_set_current_user( $this->admin );
		set_current_screen( 'dashboard' );
		$submenu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		( new Menu() )->add_pages();
		Menu::arrange();
		$slugs = array_column( $submenu[ Menu::SLUG ], 2 );
		$this->assertSame( array( PostsPage::SLUG, AddPostsPage::SLUG, Menu::SLUG ), array_slice( $slugs, 0, 3 ) );
		$this->assertNotContains( EditorPage::SLUG, $slugs );
		$this->assertNotContains( SiteSettingsPage::SLUG, $slugs );
		// Registered without a parent: the address WordPress checks access against.
		$this->assertArrayHasKey( 'admin_page_' . EditorPage::SLUG, $_registered_pages );
		$this->assertArrayHasKey( 'admin_page_' . SiteSettingsPage::SLUG, $_registered_pages );
		$_GET = array( 'page' => EditorPage::SLUG ); // phpcs:ignore
		$this->assertSame( Menu::SLUG, Menu::parent_file( 'x' ) );
		$this->assertSame( PostsPage::SLUG, Menu::submenu_file( 'x' ) );
	}
}
