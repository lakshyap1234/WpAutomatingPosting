<?php
/**
 * Plugin Name: Content Publisher: local development (agency)
 * Description: LOCAL DEVELOPMENT ONLY (dev/README.md). Lets this agency site call the
 *              local client site over HTTPS. Never install on a real site.
 *
 * Content Publisher calls client sites with wp_safe_remote_request(), which refuses
 * hosts that resolve to private or loopback addresses (in Docker, the client's
 * hostname resolves to the proxy container's private address) and checks TLS against
 * WordPress's own CA list (which doesn't include your local mkcert CA). For the dev
 * hostnames only, this allows the address and adds the local CA. Every other host is
 * treated exactly as on a real site.
 *
 * Also: an offline stand-in for the AI (CPUB_DEV_RECORDED_AI), so the shipped samples
 * can be processed without an API key.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Belt and braces: does nothing unless the site says it is a local development site.
if ( 'local' !== wp_get_environment_type() ) {
	return;
}

/** The dev hostnames (CPUB_DEV_HOSTS, comma-separated; default agency.test,client.test). */
function cpub_dev_hosts(): array {
	$hosts = defined( 'CPUB_DEV_HOSTS' ) ? (string) CPUB_DEV_HOSTS : 'agency.test,client.test';
	return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', explode( ',', $hosts ) ) ) ) );
}

function cpub_dev_is_dev_url( string $url ): bool {
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	return '' !== $host && in_array( $host, cpub_dev_hosts(), true );
}

// The dev hosts resolve to private/loopback addresses; let wp_safe_remote_*() reach them.
add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) {
		return $external || in_array( strtolower( (string) $host ), cpub_dev_hosts(), true );
	},
	10,
	2
);

// Verify the dev hosts' certificates against the local CA (mkcert's rootCA.pem), and only
// theirs: requests to real sites (an AI provider, web images) keep WordPress's CA list.
add_filter(
	'http_request_args',
	static function ( $args, $url ) {
		$ca = defined( 'CPUB_DEV_CA_FILE' ) ? (string) CPUB_DEV_CA_FILE : '';
		if ( cpub_dev_is_dev_url( (string) $url ) && '' !== $ca && is_readable( $ca ) ) {
			$args['sslverify']       = true;
			$args['sslcertificates'] = $ca;
		}
		return $args;
	},
	10,
	2
);

// Offline stand-in for the AI: with CPUB_DEV_RECORDED_AI true, a post whose lines match a
// shipped sample (content-publisher/samples/*.txt) gets that sample's recorded structure
// (*.json) instead of an AI call. Anything else fails with a clear message: set an API key
// under Content Publisher > Settings and turn this off to use the real AI.
add_filter(
	'cpub_publisher_ai_provider',
	static function ( $provider ) {
		if ( ! defined( 'CPUB_DEV_RECORDED_AI' ) || ! CPUB_DEV_RECORDED_AI || ! interface_exists( \CPub\Publisher\Pipeline\Llm\Provider::class ) ) {
			return $provider;
		}
		return new class() implements \CPub\Publisher\Pipeline\Llm\Provider {
			public function name(): string {
				return 'recorded';
			}
			public function model(): string {
				return 'samples';
			}
			public function session( string $system, string $user ): \CPub\Publisher\Pipeline\Llm\Session {
				return new class( $user ) implements \CPub\Publisher\Pipeline\Llm\Session {
					private string $user;
					public function __construct( string $user ) {
						$this->user = $user;
					}
					public function ask(): array {
						preg_match_all( '/^L\s*\d+ \| (.*)$/m', $this->user, $m );
						$key = md5( implode( "\n", array_map( fn( $l ) => str_replace( ' ⇥ ', "\t", $l ), $m[1] ) ) );
						foreach ( (array) glob( WP_PLUGIN_DIR . '/content-publisher/samples/*.json' ) as $json ) {
							$src = substr( $json, 0, -5 ) . '.txt';
							if ( ! is_file( $src ) ) {
								continue;
							}
							$lines = \CPub\Publisher\Pipeline\Ingest::ingest( (string) file_get_contents( $src ) )['lines'];
							if ( md5( implode( "\n", array_column( $lines, 'raw' ) ) ) === $key ) {
								return array(
									'input'     => json_decode( (string) file_get_contents( $json ), true ),
									'truncated' => false,
									'usage'     => array( 'total_tokens' => 0 ),
								);
							}
						}
						throw new \CPub\Publisher\Pipeline\Llm\LlmException( 'The offline stand-in AI only knows the files in content-publisher/samples/. For other posts, add an API key under Content Publisher > Settings and set CPUB_DEV_RECORDED_AI=0 in dev/.env.' );
					}
					public function reject( string $feedback ): void {
					}
				};
			}
		};
	}
);
