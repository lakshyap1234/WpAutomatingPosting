<?php
/**
 * AI settings (encrypted key), the Settings page's save handler, and the
 * Pipeline test page.
 */

use CPub\Publisher\Admin\PipelinePage;
use CPub\Publisher\Admin\SettingsPage;
use CPub\Publisher\Installer;
use CPub\Publisher\Pipeline\Llm\Anthropic;
use CPub\Publisher\Pipeline\Llm\Gemini;
use CPub\Publisher\Pipeline\Llm\Http;
use CPub\Publisher\Settings\AiSettings;

class AiSettingsAndPagesTest extends PipelineTestCase {

	private const KEY = 'AIzaSyTESTtestTESTtest1234';

	private int $admin;

	public function set_up() {
		parent::set_up();
		Installer::activate();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		add_filter( 'cpub_publisher_exit_after_redirect', '__return_false' );
		add_filter( 'wp_redirect', '__return_false' );
		Http::$backoff = 0;
	}

	public function tear_down() {
		$_POST = $_REQUEST = array(); // phpcs:ignore
		unset( $_SERVER['REQUEST_METHOD'] );
		Http::$backoff = 8;
		parent::tear_down();
	}

	public function test_defaults_to_gemini_without_a_key() {
		$this->assertSame( array( 'provider' => 'gemini', 'model' => Gemini::DEFAULT_MODEL, 'key_hint' => '', 'has_key' => false ), AiSettings::get() );
		$this->assertNull( AiSettings::provider() );
	}

	public function test_key_is_stored_encrypted_with_only_a_hint_visible() {
		$this->assertTrue( AiSettings::save( 'gemini', '', self::KEY ) );
		$raw = get_option( AiSettings::OPTION );
		$this->assertStringNotContainsString( self::KEY, wp_json_encode( $raw ) );
		$this->assertStringNotContainsString( 'TESTtest', (string) $raw['key'] );
		$this->assertSame( '1234', AiSettings::get()['key_hint'] );
		$this->assertTrue( AiSettings::get()['has_key'] );
		$p = AiSettings::provider();
		$this->assertInstanceOf( Gemini::class, $p );
		$this->assertSame( Gemini::DEFAULT_MODEL, $p->model() );
		// The key reaches the provider intact.
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$seen ) {
				$seen = $args['headers']['x-goog-api-key'];
				return array( 'headers' => array(), 'body' => '{"status":"completed","outputs":[{"type":"text","text":"{}"}]}', 'response' => array( 'code' => 200 ), 'cookies' => array(), 'filename' => null );
			},
			10,
			2
		);
		$p->session( 'S', 'P' )->ask();
		$this->assertSame( self::KEY, $seen );
	}

	public function test_a_key_copied_to_another_option_does_not_decrypt() {
		AiSettings::save( 'gemini', '', self::KEY );
		$raw = get_option( AiSettings::OPTION );
		$this->expectException( \RuntimeException::class );
		\CPub\Publisher\Crypto\Vault::decrypt( $raw['key'], 'cpub:site:1:access' ); // bound to its own context
	}

	public function test_saving_without_a_key_keeps_it_and_remove_clears_it() {
		AiSettings::save( 'gemini', '', self::KEY );
		$this->assertTrue( AiSettings::save( 'gemini', 'gemini-3.8-flash', null ) );
		$this->assertTrue( AiSettings::get()['has_key'] );
		$this->assertSame( 'gemini-3.8-flash', AiSettings::provider()->model() );
		AiSettings::save( 'gemini', '', '' );
		$this->assertFalse( AiSettings::get()['has_key'] );
		$this->assertNull( AiSettings::provider() );
	}

	public function test_switching_provider_drops_the_old_key_and_default_model() {
		AiSettings::save( 'gemini', '', self::KEY );
		AiSettings::save( 'anthropic', '', null );
		$this->assertFalse( AiSettings::get()['has_key'] );
		$this->assertSame( Anthropic::DEFAULT_MODEL, AiSettings::get()['model'] );
		AiSettings::save( 'anthropic', '', 'sk-ant-api03-abcdefghijklmnop' );
		$this->assertInstanceOf( Anthropic::class, AiSettings::provider() );
	}

	public function test_bad_input_is_refused_and_nothing_changes() {
		AiSettings::save( 'gemini', '', self::KEY );
		$before = get_option( AiSettings::OPTION );
		foreach (
			array(
				array( 'openai', '', null, 'cpub_provider' ),
				array( 'gemini', 'claude-haiku-4-5', null, 'cpub_model' ),
				array( 'gemini', 'gemini-x"><script>', null, 'cpub_model' ),
				array( 'gemini', '', 'AIza...', 'cpub_key' ),
				array( 'gemini', '', 'short', 'cpub_key' ),
				array( 'gemini', '', 'AIzaSy with spaces in the middle', 'cpub_key' ),
			) as [ $provider, $model, $key, $code ]
		) {
			$r = AiSettings::save( $provider, $model, $key );
			$this->assertSame( $code, is_wp_error( $r ) ? $r->get_error_code() : 'saved', "{$provider} {$model} {$key}" );
		}
		$this->assertSame( $before, get_option( AiSettings::OPTION ) );
	}

	public function test_a_tampered_key_means_no_provider() {
		AiSettings::save( 'gemini', '', self::KEY );
		$o        = get_option( AiSettings::OPTION );
		$o['key'] = substr( $o['key'], 0, -4 ) . 'AAAA';
		update_option( AiSettings::OPTION, $o );
		$this->assertNull( AiSettings::provider() );
	}

	public function test_settings_save_needs_manage_and_nonce() {
		$_POST = $_REQUEST = array( 'provider' => 'gemini', 'model' => '', 'api_key' => self::KEY, '_wpnonce' => wp_create_nonce( SettingsPage::ACTION ) ); // phpcs:ignore
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			SettingsPage::save();
			$this->fail( 'editor must not save' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( AiSettings::get()['has_key'] );
		}
		wp_set_current_user( $this->admin );
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = 'forged'; // phpcs:ignore
		try {
			SettingsPage::save();
			$this->fail( 'forged nonce must be refused' );
		} catch ( WPDieException $e ) {
			$this->assertFalse( AiSettings::get()['has_key'] );
		}
		$_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce( SettingsPage::ACTION ); // phpcs:ignore
		SettingsPage::save();
		$this->assertTrue( AiSettings::get()['has_key'] );

		// An empty key field keeps the key; the checkbox removes it.
		$_POST['api_key'] = '';
		SettingsPage::save();
		$this->assertTrue( AiSettings::get()['has_key'] );
		$_POST['remove_key'] = '1';
		SettingsPage::save();
		$this->assertFalse( AiSettings::get()['has_key'] );
	}

	public function test_settings_page_never_prints_the_key() {
		AiSettings::save( 'gemini', '', self::KEY );
		ob_start();
		( new SettingsPage() )->render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'TESTtest', $html );
		$this->assertStringContainsString( 'ends in …1234', $html );
		$this->assertStringContainsString( 'paid API key', $html );
	}

	private function run_page( array $post ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = $_REQUEST = $post + array( 'cpub_run' => '1', '_wpnonce' => wp_create_nonce( PipelinePage::NONCE ) ); // phpcs:ignore
		ob_start();
		( new PipelinePage() )->render();
		return (string) ob_get_clean();
	}

	public function test_pipeline_page_runs_a_sample_with_its_recorded_structure() {
		$html = $this->run_page( array( 'cpub_sample' => '06-coffee-grinder.txt', 'cpub_recorded' => '1' ) );
		$this->assertStringContainsString( 'id="cpub-result" data-ai="0" data-attempts="0" data-errors="0"', $html );
		$this->assertStringContainsString( 'Fidelity check passed', $html );
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringNotContainsString( 'cpub-pipeline-error', $html );
	}

	public function test_pipeline_page_runs_every_sample_with_recorded_structure() {
		foreach ( glob( dirname( __DIR__ ) . '/samples/*.txt' ) as $f ) {
			$html = $this->run_page( array( 'cpub_sample' => basename( $f ), 'cpub_recorded' => '1' ) );
			$this->assertStringContainsString( 'Fidelity check passed', $html, basename( $f ) );
		}
	}

	public function test_pipeline_page_with_the_ai_uses_the_configured_provider() {
		AiSettings::save( 'gemini', '', self::KEY );
		$map = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/samples/02-hardwrapped.json' ), true );
		add_filter(
			'pre_http_request',
			fn() => array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'status' => 'completed', 'outputs' => array( array( 'type' => 'text', 'text' => wp_json_encode( $map ) ) ), 'usage' => array( 'total_tokens' => 1000 ) ) ),
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
				'filename' => null,
			)
		);
		$html = $this->run_page( array( 'cpub_sample' => '02-hardwrapped.txt' ) );
		$this->assertStringContainsString( 'data-ai="1" data-attempts="1"', $html );
		$this->assertStringContainsString( 'about 1000 tokens', $html );
	}

	public function test_pipeline_page_shows_ai_errors_plainly() {
		$html = $this->run_page( array( 'cpub_sample' => '02-hardwrapped.txt' ) );
		$this->assertStringContainsString( 'id="cpub-pipeline-error"', $html );
		$this->assertStringContainsString( 'No AI provider is set up', $html );
		$this->assertStringContainsString( 'No AI key is set', $html );
	}

	public function test_pipeline_page_refuses_unknown_samples_and_path_tricks() {
		foreach ( array( '../../../wp-config.php', '..%2F02-hardwrapped.txt', 'nope.txt', '' ) as $name ) {
			$html = $this->run_page( array( 'cpub_sample' => $name, 'cpub_recorded' => '1' ) );
			$this->assertStringContainsString( 'Choose a file to upload or one of the samples.', $html, $name );
		}
	}

	public function test_pipeline_page_needs_manage_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		try {
			$this->run_page( array( 'cpub_sample' => '02-hardwrapped.txt', 'cpub_recorded' => '1' ) );
			$this->fail( 'editor must be refused' );
		} catch ( WPDieException $e ) {
			ob_end_clean();
		}
		wp_set_current_user( $this->admin );
		try {
			$this->run_page( array( 'cpub_sample' => '02-hardwrapped.txt', 'cpub_recorded' => '1', '_wpnonce' => 'forged' ) );
			$this->fail( 'forged nonce must be refused' );
		} catch ( WPDieException $e ) {
			ob_end_clean();
			$this->assertTrue( true );
		}
	}

	public function test_pipeline_page_output_is_escaped() {
		// Sample 04 has "<fast>" and "&" in its text.
		$html = $this->run_page( array( 'cpub_sample' => '04-cp1252-crlf.txt', 'cpub_recorded' => '1' ) );
		$this->assertStringContainsString( 'more \\&lt;fast&gt;', $html );          // Markdown textarea: \<fast>
		$this->assertStringContainsString( 'more &amp;lt;fast&amp;gt;', $html ); // block markup textarea
		$this->assertStringContainsString( 'more &lt;fast&gt;', $html );         // in the preview
		$this->assertStringNotContainsString( '<fast>', $html );
	}
}
