<?php
/**
 * Self-hosted updates, until the plugin is listed on WordPress.org.
 *
 * The main file's "Update URI: cpub-connector" header tells WordPress not to ask
 * WordPress.org about this plugin (so a different plugin there with the same
 * name can never be offered over it), and to ask the update_plugins_cpub-connector
 * filter instead. That reads the manifest at update_url (config/agency.php, set by
 * the build profile; see DISTRIBUTION.md). WordPress shows the update on the
 * Plugins screen as usual, and installs it only after the downloaded zip matches
 * the manifest's SHA-256.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

use CPub\Connector\OAuth\Agency;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updates {

	/** The host part of the plugin's Update URI header. */
	public const HOST = 'cpub-connector';
	public const SLUG = 'content-publisher-connector';

	private const CACHE       = 'cpub_connector_update';
	private const CACHE_TTL   = 43200; // 12 hours
	private const RETRY_AFTER = 3600; // after a failed check

	public static function register(): void {
		add_filter( 'update_plugins_' . self::HOST, array( self::class, 'check' ), 10, 2 );
		add_filter( 'plugins_api', array( self::class, 'info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( self::class, 'download' ), 10, 4 );
	}

	/**
	 * The update for WordPress's list (it compares the version itself), or false.
	 *
	 * @param array|false $update
	 * @return array|false
	 */
	public static function check( $update, $plugin_data = array() ) {
		$release = self::latest();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'package'      => $release['package'],
			'url'          => Agency::config()['url'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
		);
	}

	/**
	 * "View details" on the Plugins screen.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::latest();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Content Publisher Connector',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => esc_html( Agency::name() ),
			'homepage'      => Agency::config()['url'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'download_link' => $release['package'],
			'sections'      => array( 'changelog' => '' !== $release['changelog'] ? $release['changelog'] : '<p>No changelog was published with this version.</p>' ),
		);
	}

	/**
	 * Downloads our package and checks it against the manifest's SHA-256 before
	 * WordPress unpacks it. Other packages are left to WordPress.
	 *
	 * @param false|string|\WP_Error $reply
	 * @param string                 $package
	 * @return false|string|\WP_Error the downloaded file
	 */
	public static function download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		$release = false === $reply ? self::latest() : null;
		if ( ! $release || $package !== $release['package'] ) {
			return $reply;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! self::verify( $file, $release['sha256'] ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'cpub_update_checksum', 'The downloaded update doesn\'t match its published checksum, so it wasn\'t installed. Try again later, or ask ' . Agency::name() . '.' );
		}
		return $file;
	}

	public static function verify( string $file, string $sha256 ): bool {
		$actual = is_readable( $file ) ? (string) hash_file( 'sha256', $file ) : '';
		return '' !== $actual && hash_equals( strtolower( $sha256 ), $actual );
	}

	/** The newest release in the manifest (cached), or null when there's no channel or no usable manifest. */
	public static function latest( bool $fresh = false ): ?array {
		$url = Agency::config()['update_url'];
		if ( '' === $url ) {
			return null;
		}
		$cached = $fresh ? false : get_site_transient( self::CACHE );
		if ( is_array( $cached ) && ( $cached['url'] ?? '' ) === $url ) {
			return $cached['release'];
		}
		$response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/json' ) ) );
		$release  = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
			? null
			: self::parse( json_decode( wp_remote_retrieve_body( $response ), true ), $url );
		set_site_transient( self::CACHE, array( 'url' => $url, 'release' => $release ), $release ? self::CACHE_TTL : self::RETRY_AFTER );
		return $release;
	}

	/**
	 * A manifest's release, if it is complete and safe to offer: a version, a
	 * SHA-256, and a https:// zip on the manifest's own host.
	 *
	 * @param mixed $data
	 */
	public static function parse( $data, string $manifest_url ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$version = (string) ( $data['version'] ?? '' );
		$package = (string) ( $data['download_url'] ?? '' );
		$sha256  = strtolower( (string) ( $data['sha256'] ?? '' ) );
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) || ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return null;
		}
		if ( ! str_starts_with( $package, 'https://' ) || wp_parse_url( $package, PHP_URL_HOST ) !== wp_parse_url( $manifest_url, PHP_URL_HOST ) ) {
			return null;
		}
		$text = static fn( string $key ) => preg_match( '/^\d+(\.\d+){0,2}$/', (string) ( $data[ $key ] ?? '' ) ) ? (string) $data[ $key ] : '';
		return array(
			'version'      => $version,
			'package'      => $package,
			'sha256'       => $sha256,
			'requires'     => $text( 'requires' ),
			'requires_php' => $text( 'requires_php' ),
			'tested'       => $text( 'tested' ),
			'changelog'    => wp_kses_post( (string) ( $data['changelog'] ?? '' ) ),
		);
	}

	public static function forget(): void {
		delete_site_transient( self::CACHE );
	}
}
