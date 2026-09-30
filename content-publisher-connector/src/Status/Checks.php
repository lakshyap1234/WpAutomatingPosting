<?php
/**
 * Health checks for the client site, shown under Settings > Content Publisher.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Status;

use CPub\Connector\Installer;
use CPub\Connector\OAuth\Agency;
use CPub\Connector\OAuth\Keys;
use CPub\Connector\Rest\AuthorizationProbe;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Checks {

	public const OK    = 'ok';
	public const WARN  = 'warning';
	public const ERROR = 'error';

	/**
	 * @return array<int, array{id:string, label:string, status:string, detail:string}>
	 */
	public static function run(): array {
		return array(
			self::https(),
			self::libraries(),
			self::setup(),
			self::agency(),
			self::authorization_header(),
		);
	}

	public static function https(): array {
		if ( wp_is_using_https() ) {
			return self::result( 'https', 'HTTPS', self::OK, 'The site uses HTTPS, which OAuth requires.' );
		}
		return self::result( 'https', 'HTTPS', self::ERROR, 'The site address is not https://. The agency can\'t be given access until the site uses HTTPS.' );
	}

	public static function libraries(): array {
		if ( class_exists( \CPub\Connector\Vendor\League\OAuth2\Server\AuthorizationServer::class ) ) {
			return self::result( 'libraries', 'Bundled libraries', self::OK, 'The OAuth library is loaded under the plugin\'s own namespace, so other plugins\' copies can\'t conflict.' );
		}
		return self::result( 'libraries', 'Bundled libraries', self::ERROR, 'The vendor-prefixed folder is missing or incomplete. Reinstall the plugin from the release zip.' );
	}

	public static function setup(): array {
		$missing = Installer::missing_tables();
		if ( $missing ) {
			return self::result( 'setup', 'Database and keys', self::ERROR, 'Missing tables: ' . implode( ', ', $missing ) . '. Deactivate and reactivate the plugin.' );
		}
		if ( ! Keys::ready() ) {
			return self::result( 'setup', 'Database and keys', self::ERROR, 'The security keys could not be created; this server\'s OpenSSL may be misconfigured. Ask the host to check that PHP can create keys with openssl_pkey_new().' );
		}
		return self::result( 'setup', 'Database and keys', self::OK, 'Tables and security keys are in place.' );
	}

	public static function agency(): array {
		$config = Agency::config();
		if ( ! $config['redirect_uris'] ) {
			return self::result( 'agency', 'Trusted agency', self::ERROR, 'No valid return address is configured, so nobody can connect. Reinstall the plugin from the zip the agency sent.' );
		}
		$hosts = array_unique( array_map( fn( $u ) => (string) wp_parse_url( $u, PHP_URL_HOST ), $config['redirect_uris'] ) );
		$test  = array_filter( $config['redirect_uris'], array( Agency::class, 'is_loopback' ) );
		return self::result(
			'agency',
			'Trusted agency',
			$test ? self::WARN : self::OK,
			sprintf( 'Only %s can connect, returning to %s.', $config['name'], implode( ', ', $hosts ) ) . ( $test ? ' This is a test build: it also allows a test tool on the approving administrator\'s own computer. Don\'t use it on a live site.' : '' )
		);
	}

	public static function authorization_header( ?array $probe = null ): array {
		$probe ??= AuthorizationProbe::run();
		$label   = 'Authorization header';
		return match ( $probe['result'] ) {
			AuthorizationProbe::RECEIVED => self::result( 'authorization_header', $label, self::OK, 'Access tokens sent in the Authorization header reach WordPress.' ),
			AuthorizationProbe::MISSING  => self::result(
				'authorization_header',
				$label,
				self::WARN,
				'The web server removes the standard Authorization header before WordPress sees it. The connection still works, because the agency also sends its token in a backup header, but fixing it is better: on Apache, add this line to .htaccess above "# BEGIN WordPress": SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1 . On other servers, ask the host to pass the Authorization header to PHP.'
			),
			default                      => self::result( 'authorization_header', $label, self::WARN, 'Could not be tested. ' . $probe['detail'] ),
		};
	}

	private static function result( string $id, string $label, string $status, string $detail ): array {
		return compact( 'id', 'label', 'status', 'detail' );
	}
}
