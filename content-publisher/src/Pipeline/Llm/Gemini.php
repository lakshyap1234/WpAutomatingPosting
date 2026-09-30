<?php
/**
 * Google Gemini, structured JSON output via the Interactions API (the same
 * call the prototype made through @google/genai). Retries are stateless: the
 * original prompt is resent with the previous answer and what was wrong with
 * it. Nothing is stored on Google's side (store: false).
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline\Llm;

use CPub\Publisher\Pipeline\StructureMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Gemini implements Provider {

	public const ENDPOINT      = 'https://generativelanguage.googleapis.com/v1beta/interactions';
	public const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

	public function __construct( private string $api_key, private string $model = self::DEFAULT_MODEL ) {
	}

	public function name(): string {
		return 'gemini';
	}

	public function model(): string {
		return $this->model;
	}

	public function session( string $system, string $user_content ): Session {
		$key   = $this->api_key;
		$model = $this->model;
		return new class( $key, $model, $system, $user_content ) implements Session {
			private ?string $last     = null;
			private ?string $feedback = null;

			public function __construct( private string $key, private string $model, private string $system, private string $user ) {
			}

			public function ask(): array {
				$input = null !== $this->feedback
					? "{$this->user}\n\nYour previous answer was:\n{$this->last}\n\n{$this->feedback}"
					: $this->user;
				$r = Http::post_json(
					Gemini::ENDPOINT,
					array( 'x-goog-api-key' => $this->key ),
					array(
						'model'              => $this->model,
						'system_instruction' => $this->system,
						'input'              => $input,
						'response_format'    => array( 'type' => 'text', 'mime_type' => 'application/json', 'schema' => StructureMap::schema() ),
						'generation_config'  => array( 'max_output_tokens' => 8192 ),
						'store'              => false,
					)
				);
				if ( 200 !== $r['status'] ) {
					$msg = Http::error_message( $r );
					if ( in_array( $r['status'], array( 400, 401, 403 ), true ) && preg_match( '/api key|permission|unauth/i', $msg ) ) {
						throw new LlmException( "Gemini rejected the API key: {$msg}" );
					}
					if ( 404 === $r['status'] ) {
						throw new LlmException( "Gemini model \"{$this->model}\" isn't available to this key: {$msg}" );
					}
					throw new LlmException( "Gemini error: {$msg}", in_array( $r['status'], Http::RETRYABLE, true ) ); // still busy after retries: try again later
				}
				$d          = (array) $r['data'];
				$this->last = self::output_text( $d );
				$status     = $d['status'] ?? 'completed';
				$truncated  = 'incomplete' === $status;
				$usage      = (array) ( $d['usage'] ?? array() );
				if ( ! in_array( $status, array( 'completed', 'incomplete' ), true ) ) {
					return array( 'error' => "Gemini interaction ended with status \"{$status}\".", 'truncated' => false, 'usage' => $usage );
				}
				$json = json_decode( $this->last, true );
				if ( null === $json && 'null' !== trim( $this->last ) ) {
					return array( 'error' => 'Response was not valid JSON.', 'truncated' => $truncated, 'usage' => $usage );
				}
				return array( 'input' => $json, 'truncated' => $truncated, 'usage' => $usage );
			}

			public function reject( string $feedback ): void {
				$this->feedback = $feedback;
			}

			/** Text of the model's last output: `outputs` or the last model_output step. */
			private static function output_text( array $d ): string {
				$content = array();
				if ( isset( $d['outputs'] ) && is_array( $d['outputs'] ) ) {
					$content = $d['outputs'];
				} elseif ( isset( $d['steps'] ) && is_array( $d['steps'] ) ) {
					foreach ( array_reverse( $d['steps'] ) as $step ) {
						if ( 'model_output' === ( $step['type'] ?? '' ) && ! empty( $step['content'] ) ) {
							$content = $step['content'];
							break;
						}
					}
				}
				$text = '';
				foreach ( (array) $content as $item ) {
					if ( 'text' === ( $item['type'] ?? '' ) ) {
						$text .= (string) ( $item['text'] ?? '' );
					}
				}
				return $text;
			}
		};
	}
}
