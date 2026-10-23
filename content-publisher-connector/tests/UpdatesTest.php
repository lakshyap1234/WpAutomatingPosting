<?php

use CPub\Connector\OAuth\Agency;
use CPub\Connector\Updates;

/**
 * The self-hosted update channel: the Update URI header keeps WordPress.org out,
 * the manifest at update_url offers new versions, and a download is installed
 * only if it matches the manifest's SHA-256.
 */
class UpdatesTest extends WP_UnitTestCase {

	private const MANIFEST = 'https://updates.example/connector/update.json';
	private const ZIP      = "PK\x03\x04 a stand-in for the plugin zip";

	/** url => [status, body] */
	private array $web = array();

	/** @var string[] */
	private array $requests = array();

	private string $update_url = self::MANIFEST;

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'cpub_connector_agency',
			fn() => array(
				'client_id'     => 'cpub-publisher',
				'name'          => 'Test Agency',
				'url'           => 'https://agency.test',
				'logo'          => '',
				'redirect_uris' => array( 'https://agency.test/callback' ),
				'update_url'    => $this->update_url,
			)
		);
		add_filter( 'pre_http_request', array( $this, 'fake_web' ), 10, 3 );
		Agency::reset();
		Updates::forget();
	}

	public function tear_down(): void {
		Agency::reset();
		Updates::forget();
		parent::tear_down();
	}

	public function fake_web( $pre, $args, $url ) {
		$this->requests[] = $url;
		if ( ! isset( $this->web[ $url ] ) ) {
			return new WP_Error( 'offline', 'Not on the test web.' );
		}
		[ $code, $body ] = $this->web[ $url ];
		if ( ! empty( $args['filename'] ) ) {
			file_put_contents( $args['filename'], $body ); // a streamed download, as download_url() asks for
		}
		return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => $args['filename'] ?? null );
	}

	private function manifest( array $over = array() ): array {
		return array_merge(
			array(
				'version'      => '9.0.0',
				'download_url' => 'https://updates.example/connector/content-publisher-connector-9.0.0.zip',
				'sha256'       => hash( 'sha256', self::ZIP ),
				'requires'     => '6.5',
				'requires_php' => '8.2',
				'tested'       => '6.9',
				'changelog'    => '<ul><li>New things</li></ul><script>alert(1)</script>',
			),
			$over
		);
	}

	private function publish( array $manifest ): void {
		$this->web[ self::MANIFEST ] = array( 200, wp_json_encode( $manifest ) );
		$this->web[ (string) $manifest['download_url'] ] = array( 200, self::ZIP );
	}

	private static function check() {
		return apply_filters( 'update_plugins_' . Updates::HOST, false, array(), 'content-publisher-connector/content-publisher-connector.php', array() );
	}

	// ------------------------------------------------------------ WordPress.org

	public function test_the_update_uri_header_sends_wordpress_to_our_filter(): void {
		$header = get_file_data( CPUB_CONNECTOR_FILE, array( 'uri' => 'Update URI' ) )['uri'];
		$this->assertSame( 'cpub-connector', $header );
		// How WordPress turns the header into the filter name (wp_update_plugins()).
		$this->assertSame( Updates::HOST, wp_parse_url( sanitize_url( $header ), PHP_URL_HOST ) );
		$this->assertNotContains( wp_parse_url( sanitize_url( $header ), PHP_URL_HOST ), array( 'w.org', 'wordpress.org' ), 'so WordPress.org is never asked' );
	}

	// ------------------------------------------------------------ offering an update

	public function test_a_newer_version_is_offered(): void {
		$this->publish( $this->manifest() );
		$update = self::check();
		$this->assertIsArray( $update );
		$this->assertSame( '9.0.0', $update['version'] );
		$this->assertSame( 'https://updates.example/connector/content-publisher-connector-9.0.0.zip', $update['package'] );
		$this->assertSame( Updates::SLUG, $update['slug'] );
		$this->assertSame( '8.2', $update['requires_php'] );
		$this->assertSame( array( self::MANIFEST ), $this->requests );
	}

	public function test_no_channel_means_no_request(): void {
		$this->update_url = '';
		Agency::reset();
		$this->assertFalse( self::check() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_manifest_is_cached(): void {
		$this->publish( $this->manifest() );
		self::check();
		self::check();
		$this->assertCount( 1, $this->requests );
	}

	public function test_a_failed_check_is_not_repeated_on_every_page(): void {
		$this->web[ self::MANIFEST ] = array( 500, 'oops' );
		$this->assertFalse( self::check() );
		$this->assertFalse( self::check() );
		$this->assertCount( 1, $this->requests );
	}

	/** @dataProvider unsafe */
	public function test_an_incomplete_or_unsafe_manifest_offers_nothing( array $over ): void {
		$this->publish( $this->manifest( $over ) );
		$this->assertFalse( self::check() );
	}

	public static function unsafe(): array {
		return array(
			'no checksum'          => array( array( 'sha256' => '' ) ),
			'bad checksum'         => array( array( 'sha256' => 'abc' ) ),
			'no version'           => array( array( 'version' => '' ) ),
			'odd version'          => array( array( 'version' => '9.0.0<script>' ) ),
			'zip over http'        => array( array( 'download_url' => 'http://updates.example/connector/c.zip' ) ),
			'zip on another host'  => array( array( 'download_url' => 'https://evil.example/c.zip' ) ),
			'no zip'               => array( array( 'download_url' => '' ) ),
		);
	}

	public function test_not_json_offers_nothing(): void {
		$this->web[ self::MANIFEST ] = array( 200, '<html>Not found</html>' );
		$this->assertFalse( self::check() );
	}

	// ------------------------------------------------------------ View details

	public function test_view_details_shows_the_changelog_cleaned(): void {
		$this->publish( $this->manifest() );
		$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => Updates::SLUG ) );
		$this->assertIsObject( $info );
		$this->assertSame( '9.0.0', $info->version );
		$this->assertStringContainsString( '<li>New things</li>', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<script', $info->sections['changelog'] );
		$this->assertFalse( apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ), 'other plugins are left alone' );
	}

	// ------------------------------------------------------------ installing

	public function test_a_download_matching_the_checksum_is_installed(): void {
		$manifest = $this->manifest();
		$this->publish( $manifest );
		$file = apply_filters( 'upgrader_pre_download', false, $manifest['download_url'], null, array() );
		$this->assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );
		$this->assertSame( self::ZIP, file_get_contents( $file ) );
		wp_delete_file( $file );
	}

	public function test_a_download_that_does_not_match_is_refused(): void {
		$manifest = $this->manifest( array( 'sha256' => hash( 'sha256', 'something else' ) ) );
		$this->publish( $manifest );
		$result = apply_filters( 'upgrader_pre_download', false, $manifest['download_url'], null, array() );
		$this->assertWPError( $result );
		$this->assertSame( 'cpub_update_checksum', $result->get_error_code() );
	}

	public function test_other_downloads_are_left_to_wordpress(): void {
		$this->publish( $this->manifest() );
		$this->assertFalse( apply_filters( 'upgrader_pre_download', false, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array() ) );
	}
}
