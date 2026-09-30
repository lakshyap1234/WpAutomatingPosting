<?php
/**
 * wp cpub pipeline <file> — run a post through the pipeline from the command line.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Cli;

use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\Pipeline;
use CPub\Publisher\Settings\AiSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PipelineCommand {

	/**
	 * Structure a .txt (with the AI) or .md file and print the result.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The post, .txt or .md.
	 *
	 * [--map=<file>]
	 * : Use this structure map (JSON) instead of calling the AI.
	 *
	 * [--format=<format>]
	 * : markdown (default), blocks, html, or json (everything).
	 *
	 * ## EXAMPLES
	 *
	 *     wp cpub pipeline post.txt
	 *     wp cpub pipeline post.txt --format=blocks
	 *     wp cpub pipeline samples/06-coffee-grinder.txt --map=samples/06-coffee-grinder.json
	 *
	 * @when after_wp_load
	 */
	public function __invoke( array $args, array $assoc ): void {
		$file = $args[0];
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Can't read {$file}." );
		}
		$map = null;
		if ( isset( $assoc['map'] ) ) {
			$map = json_decode( (string) file_get_contents( $assoc['map'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! is_array( $map ) ) {
				\WP_CLI::error( "{$assoc['map']} isn't valid JSON." );
			}
		}
		try {
			$r = ( new Pipeline( AiSettings::provider() ) )->run( (string) file_get_contents( $file ), basename( $file ), $map ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
		switch ( $assoc['format'] ?? 'markdown' ) {
			case 'json':
				\WP_CLI::line( (string) wp_json_encode( $r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				return;
			case 'blocks':
				\WP_CLI::line( MarkdownToPost::convert( $r['markdown'] )['content'] );
				break;
			case 'html':
				\WP_CLI::line( $r['post']['content'] );
				break;
			default:
				\WP_CLI::line( rtrim( $r['markdown'] ) );
		}
		foreach ( array_merge( array_map( fn( $e ) => $e['message'], $r['post']['errors'] ), $r['warnings'] ) as $w ) {
			\WP_CLI::warning( $w );
		}
		\WP_CLI::success( sprintf( '%s: %s.', $r['name'], $r['ai'] ? "structured by the AI in {$r['attempts']} attempt(s), fidelity check passed" : ( null !== $r['map'] ? 'given structure map, fidelity check passed' : 'Markdown used as written' ) ) );
	}
}
