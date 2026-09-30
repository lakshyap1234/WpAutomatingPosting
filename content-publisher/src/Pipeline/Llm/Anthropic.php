<?php
/**
 * Anthropic Claude, forced tool call. Retries continue the same conversation
 * with a tool_result error, so the model sees exactly what it got wrong.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Pipeline\Llm;

use CPub\Publisher\Pipeline\StructureMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Anthropic implements Provider {

	public const ENDPOINT      = 'https://api.anthropic.com/v1/messages';
	public const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';
	public const TOOL          = 'submit_structure';

	public function __construct( private string $api_key, private string $model = self::DEFAULT_MODEL ) {
	}

	public function name(): string {
		return 'anthropic';
	}

	public function model(): string {
		return $this->model;
	}

	public function session( string $system, string $user_content ): Session {
		return new class( $this->api_key, $this->model, $system, $user_content ) implements Session {
			private array $messages;
			private ?array $last = null;

			public function __construct( private string $key, private string $model, private string $system, string $user ) {
				$this->messages = array( array( 'role' => 'user', 'content' => $user ) );
			}

			public function ask(): array {
				$r = Http::post_json(
					Anthropic::ENDPOINT,
					array( 'x-api-key' => $this->key, 'anthropic-version' => '2023-06-01' ),
					array(
						'model'       => $this->model,
						'max_tokens'  => 8192,
						'system'      => $this->system,
						'tools'       => array(
							array(
								'name'         => Anthropic::TOOL,
								'description'  => 'Submit the structure map for the post. Reference lines ONLY by their numeric ID. Never include post text.',
								'input_schema' => StructureMap::schema(),
							),
						),
						'tool_choice' => array( 'type' => 'tool', 'name' => Anthropic::TOOL ),
						'messages'    => $this->messages,
					)
				);
				if ( 200 !== $r['status'] ) {
					$msg = Http::error_message( $r );
					if ( 401 === $r['status'] ) {
						throw new LlmException( "Anthropic rejected the API key: {$msg}" );
					}
					if ( 404 === $r['status'] ) {
						throw new LlmException( "Anthropic model \"{$this->model}\" isn't available to this key: {$msg}" );
					}
					throw new LlmException( "Anthropic error: {$msg}", in_array( $r['status'], Http::RETRYABLE, true ) ); // still busy after retries: try again later
				}
				$this->last = (array) $r['data'];
				$call       = null;
				foreach ( (array) ( $this->last['content'] ?? array() ) as $c ) {
					if ( 'tool_use' === ( $c['type'] ?? '' ) ) {
						$call = $c;
						break;
					}
				}
				$stop = (string) ( $this->last['stop_reason'] ?? '' );
				return $call
					? array( 'input' => $call['input'] ?? null, 'truncated' => 'max_tokens' === $stop, 'usage' => (array) ( $this->last['usage'] ?? array() ) )
					: array( 'error' => "Model returned no tool call (stop_reason: {$stop}).", 'truncated' => 'max_tokens' === $stop, 'usage' => (array) ( $this->last['usage'] ?? array() ) );
			}

			public function reject( string $feedback ): void {
				$content = (array) ( $this->last['content'] ?? array() );
				$call    = null;
				foreach ( $content as $c ) {
					if ( 'tool_use' === ( $c['type'] ?? '' ) ) {
						$call = $c;
						break;
					}
				}
				$this->messages[] = array( 'role' => 'assistant', 'content' => $content );
				$this->messages[] = array(
					'role'    => 'user',
					'content' => $call ? array( array( 'type' => 'tool_result', 'tool_use_id' => $call['id'], 'is_error' => true, 'content' => $feedback ) ) : $feedback,
				);
			}
		};
	}
}
