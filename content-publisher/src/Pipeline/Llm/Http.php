<?php
/**
 * JSON POST to an AI provider through WordPress's HTTP API, with backoff on
 * rate limits and temporary errors (free tiers hit 429 often).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline\Llm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Http {

	public const TIMEOUT   = 90;
	public const RETRYABLE = array( 429, 500, 502, 503, 504, 529 ); // 529: Anthropic "overloaded"

	/** Seconds to wait before retry 1, 2 (unless the provider says Retry-After). Tests set it to 0. */
	public static int $backoff = 8;

	/**
	 * Unix time by which all calls must be done (set per structuring run by the
	 * Annotator), so retries can't add up to more than a page request can wait.
	 */
	public static ?float $deadline = null;

	/**
	 * @return array{status:int, data:mixed, raw:string}
	 * @throws LlmException when the provider can't be reached at all
	 */
	public static function post_json( string $url, array $headers, array $body, int $retries = 2 ): array {
		for ( $i = 0; ; $i++ ) {
			$left = null === self::$deadline ? self::TIMEOUT : self::$deadline - microtime( true );
			if ( $left < 5 ) {
				throw new LlmException( 'The AI service took too long to answer. Try again in a minute.', true );
			}
			$response = wp_remote_post(
				$url,
				array(
					'timeout' => (int) min( self::TIMEOUT, $left ),
					'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
					'body'    => wp_json_encode( $body ),
				)
			);
			if ( is_wp_error( $response ) ) {
				// Retry a failed connection, not a timeout: the request may still be
				// running (and billed), and a second long wait would outlast the page.
				if ( $i < $retries && ! preg_match( '/timed? ?out/i', $response->get_error_message() ) && self::pause( self::$backoff * ( $i + 1 ) ) ) {
					continue;
				}
				throw new LlmException( 'Couldn\'t reach the AI service: ' . $response->get_error_message(), true );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $status, self::RETRYABLE, true ) && $i < $retries ) {
				$after = wp_remote_retrieve_header( $response, 'retry-after' );
				$wait  = is_numeric( $after ) ? min( 60, max( 0, (int) $after ) ) : self::$backoff * ( $i + 1 );
				if ( self::pause( $wait ) ) {
					continue;
				}
			}
			$raw = (string) wp_remote_retrieve_body( $response );
			return array( 'status' => $status, 'data' => json_decode( $raw, true ), 'raw' => $raw );
		}
	}

	/** Sleep before a retry, unless that would leave too little time for it. */
	private static function pause( int $seconds ): bool {
		if ( null !== self::$deadline && microtime( true ) + $seconds + 15 > self::$deadline ) {
			return false;
		}
		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
		return true;
	}

	/** The provider's own error message, if any. */
	public static function error_message( array $r ): string {
		$d = $r['data'];
		$m = is_array( $d ) ? ( $d['error']['message'] ?? ( is_string( $d['error'] ?? null ) ? $d['error'] : null ) ?? $d['message'] ?? null ) : null;
		return $m ? (string) $m : 'HTTP ' . $r['status'] . ' ' . mb_substr( wp_strip_all_tags( $r['raw'] ), 0, 200 );
	}
}
