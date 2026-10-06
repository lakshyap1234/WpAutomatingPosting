<?php
/**
 * Gemini and Anthropic requests and responses, through WordPress's HTTP API
 * with recorded bodies. Ported from providers.test.js.
 */

use CPub\Publisher\Pipeline\Llm\Anthropic;
use CPub\Publisher\Pipeline\Llm\Gemini;
use CPub\Publisher\Pipeline\Llm\Http;
use CPub\Publisher\Pipeline\Llm\LlmException;
use CPub\Publisher\Pipeline\StructureMap;

class ProvidersTest extends PipelineTestCase {

	private const MAP = array( 'title' => 1, 'excluded' => array(), 'blocks' => array( array( 'type' => 'paragraph', 'lines' => array( 2 ) ) ) );

	/** @var array[] requests seen: url, headers, body (decoded) */
	private array $calls = array();

	public function set_up() {
		parent::set_up();
		Http::$backoff = 0;
		$this->calls   = array();
	}

	public function tear_down() {
		Http::$backoff = 8;
		parent::tear_down();
	}

	/**
	 * Answer requests in order. Each response is [status, body array|string] or a WP_Error.
	 */
	private function respond( array $responses ): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$responses ) {
				$this->calls[] = array( 'url' => $url, 'headers' => $args['headers'], 'body' => json_decode( $args['body'], true ), 'timeout' => $args['timeout'] );
				$r             = array_shift( $responses );
				if ( $r instanceof WP_Error ) {
					return $r;
				}
				[ $status, $body ] = $r;
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
					'response' => array( 'code' => $status, 'message' => '' ),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/** An Interactions API answer, as recorded from the API. */
	private static function interaction( string $text, string $status = 'completed', bool $steps = false ): array {
		$content = array( array( 'type' => 'text', 'text' => $text ) );
		$base    = array(
			'id'     => 'v1_abc',
			'status' => $status,
			'usage'  => array( 'total_input_tokens' => 812, 'total_output_tokens' => 64, 'total_tokens' => 876 ),
		);
		return $steps
			? $base + array( 'steps' => array( array( 'type' => 'user_input', 'content' => array( array( 'type' => 'text', 'text' => 'POST' ) ) ), array( 'type' => 'model_output', 'content' => $content ) ) )
			: $base + array( 'outputs' => $content );
	}

	public function test_gemini_sends_schema_and_system_prompt_parses_json_and_stores_nothing() {
		$this->respond( array( array( 200, self::interaction( wp_json_encode( self::MAP ) ) ) ) );
		$out = ( new Gemini( 'AIzaTESTKEY', 'gemini-x' ) )->session( 'SYS', 'POST' )->ask();
		$this->assertSame( self::MAP, $out['input'] );
		$this->assertFalse( $out['truncated'] );
		$this->assertSame( 876, $out['usage']['total_tokens'] );
		$req = $this->calls[0];
		$this->assertSame( Gemini::ENDPOINT, $req['url'] );
		$this->assertSame( 'AIzaTESTKEY', $req['headers']['x-goog-api-key'] );
		$this->assertStringNotContainsString( 'AIzaTESTKEY', $req['url'] ); // key in a header, never the URL (logs)
		$this->assertSame( 'gemini-x', $req['body']['model'] );
		$this->assertSame( 'SYS', $req['body']['system_instruction'] );
		$this->assertSame( 'POST', $req['body']['input'] );
		$this->assertFalse( $req['body']['store'] );
		$this->assertSame( 'application/json', $req['body']['response_format']['mime_type'] );
		$this->assertSame( StructureMap::schema(), $req['body']['response_format']['schema'] );
	}

	public function test_gemini_reads_the_last_model_output_step() {
		$this->respond( array( array( 200, self::interaction( wp_json_encode( self::MAP ), 'completed', true ) ) ) );
		$this->assertSame( self::MAP, ( new Gemini( 'k' ) )->session( 'S', 'P' )->ask()['input'] );
	}

	public function test_gemini_invalid_json_is_retryable_and_the_retry_includes_the_previous_answer_and_feedback() {
		$this->respond( array( array( 200, self::interaction( '{not json' ) ), array( 200, self::interaction( wp_json_encode( self::MAP ) ) ) ) );
		$s     = ( new Gemini( 'k', 'm' ) )->session( 'S', 'POST' );
		$first = $s->ask();
		$this->assertArrayNotHasKey( 'input', $first );
		$this->assertMatchesRegularExpression( '/not valid JSON/', $first['error'] );
		$s->reject( 'FIX: line 3 missing' );
		$this->assertSame( self::MAP, $s->ask()['input'] );
		$this->assertMatchesRegularExpression( '/^POST[\s\S]*previous answer was:\n\{not json[\s\S]*FIX: line 3 missing$/', $this->calls[1]['body']['input'] );
	}

	public function test_gemini_incomplete_status_is_reported_as_truncated() {
		$this->respond( array( array( 200, self::interaction( '{"title":1', 'incomplete' ) ) ) );
		$this->assertTrue( ( new Gemini( 'k' ) )->session( 'S', 'P' )->ask()['truncated'] );
	}

	public function test_gemini_failed_status_is_an_error_not_a_map() {
		$this->respond( array( array( 200, self::interaction( '', 'failed' ) ) ) );
		$out = ( new Gemini( 'k' ) )->session( 'S', 'P' )->ask();
		$this->assertArrayNotHasKey( 'input', $out );
		$this->assertStringContainsString( 'failed', $out['error'] );
	}

	public function test_gemini_backs_off_on_429_then_succeeds() {
		$this->respond(
			array(
				array( 429, array( 'error' => array( 'code' => 429, 'message' => 'Resource has been exhausted (e.g. check quota).', 'status' => 'RESOURCE_EXHAUSTED' ) ) ),
				array( 503, 'upstream busy' ),
				array( 200, self::interaction( wp_json_encode( self::MAP ) ) ),
			)
		);
		$this->assertSame( self::MAP, ( new Gemini( 'k' ) )->session( 'S', 'P' )->ask()['input'] );
		$this->assertCount( 3, $this->calls );
	}

	public function test_gemini_gives_up_on_429_after_retries_with_the_providers_message() {
		$quota = array( 429, array( 'error' => array( 'code' => 429, 'message' => 'Quota exceeded for metric generate_requests_per_minute.' ) ) );
		$this->respond( array( $quota, $quota, $quota ) );
		$this->expectException( LlmException::class );
		$this->expectExceptionMessage( 'Quota exceeded for metric' );
		( new Gemini( 'k' ) )->session( 'S', 'P' )->ask();
	}

	public function test_gemini_404_explains_the_model_is_unavailable() {
		$this->respond( array( array( 404, array( 'error' => array( 'code' => 404, 'message' => 'models/gemini-nope is not found for API version v1beta', 'status' => 'NOT_FOUND' ) ) ) ) );
		$this->expectException( LlmException::class );
		$this->expectExceptionMessageMatches( '/"gemini-nope" isn\'t available/' );
		( new Gemini( 'k', 'gemini-nope' ) )->session( 'S', 'P' )->ask();
	}

	public function test_gemini_bad_key_is_named() {
		$this->respond( array( array( 400, array( 'error' => array( 'code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT' ) ) ) ) );
		$this->expectException( LlmException::class );
		$this->expectExceptionMessageMatches( '/rejected the API key: API key not valid/' );
		( new Gemini( 'bad' ) )->session( 'S', 'P' )->ask();
	}

	public function test_connection_failure_retries_but_a_timeout_does_not() {
		$this->respond( array( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ), array( 200, self::interaction( wp_json_encode( self::MAP ) ) ) ) );
		$this->assertSame( self::MAP, ( new Gemini( 'k' ) )->session( 'S', 'P' )->ask()['input'] );
		$this->assertCount( 2, $this->calls );

		$this->calls = array();
		remove_all_filters( 'pre_http_request' );
		$this->respond( array( new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 90001 milliseconds' ), array( 200, self::interaction( '{}' ) ) ) );
		try {
			( new Gemini( 'k' ) )->session( 'S', 'P' )->ask();
			$this->fail( 'expected LlmException' );
		} catch ( LlmException $e ) {
			$this->assertStringContainsString( 'Couldn\'t reach the AI service', $e->getMessage() );
		}
		$this->assertCount( 1, $this->calls );
		$this->assertSame( Http::TIMEOUT, $this->calls[0]['timeout'] );
	}

	public function test_non_json_error_body_is_shortened_and_stripped() {
		$bad = array( 502, '<html><body><h1>Bad gateway</h1>' . str_repeat( 'x', 500 ) . '</body></html>' );
		$this->respond( array( $bad, $bad, $bad ) );
		try {
			( new Gemini( 'k' ) )->session( 'S', 'P' )->ask();
			$this->fail( 'expected LlmException' );
		} catch ( LlmException $e ) {
			$this->assertStringContainsString( 'HTTP 502 Bad gateway', $e->getMessage() );
			$this->assertStringNotContainsString( '<h1>', $e->getMessage() );
			$this->assertLessThan( 260, strlen( $e->getMessage() ) );
		}
	}

	// ---- Anthropic ----

	private static function tool_use( string $id, $input, string $stop = 'tool_use' ): array {
		return array(
			'id'          => 'msg_1',
			'type'        => 'message',
			'role'        => 'assistant',
			'stop_reason' => $stop,
			'usage'       => array( 'input_tokens' => 900, 'output_tokens' => 70 ),
			'content'     => array( array( 'type' => 'tool_use', 'id' => $id, 'name' => Anthropic::TOOL, 'input' => $input ) ),
		);
	}

	public function test_anthropic_forced_tool_call_and_retry_sends_a_tool_result_error() {
		$this->respond( array( array( 200, self::tool_use( 't1', self::MAP ) ), array( 200, self::tool_use( 't2', self::MAP ) ) ) );
		$s = ( new Anthropic( 'sk-ant-test', 'claude-x' ) )->session( 'S', 'P' );
		$this->assertSame( self::MAP, $s->ask()['input'] );
		$first = $this->calls[0];
		$this->assertSame( Anthropic::ENDPOINT, $first['url'] );
		$this->assertSame( 'sk-ant-test', $first['headers']['x-api-key'] );
		$this->assertSame( '2023-06-01', $first['headers']['anthropic-version'] );
		$this->assertSame( array( 'type' => 'tool', 'name' => 'submit_structure' ), $first['body']['tool_choice'] );
		$this->assertSame( StructureMap::schema(), $first['body']['tools'][0]['input_schema'] );
		$this->assertSame( 'S', $first['body']['system'] );

		$s->reject( 'FIX IT' );
		$s->ask();
		$messages = $this->calls[1]['body']['messages'];
		$this->assertCount( 3, $messages );
		$this->assertSame( 'assistant', $messages[1]['role'] );
		$fb = end( $messages )['content'][0];
		$this->assertSame( 'tool_result', $fb['type'] );
		$this->assertSame( 't1', $fb['tool_use_id'] );
		$this->assertTrue( $fb['is_error'] );
		$this->assertSame( 'FIX IT', $fb['content'] );
	}

	public function test_anthropic_max_tokens_is_truncated_and_no_tool_call_is_an_error() {
		$this->respond( array( array( 200, self::tool_use( 't1', array( 'title' => 1 ), 'max_tokens' ) ), array( 200, array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => 'Sure!' ) ) ) ) ) );
		$s = ( new Anthropic( 'k' ) )->session( 'S', 'P' );
		$this->assertTrue( $s->ask()['truncated'] );
		$out = $s->ask();
		$this->assertStringContainsString( 'no tool call (stop_reason: end_turn)', $out['error'] );
	}

	public function test_anthropic_errors_are_named() {
		$this->respond(
			array(
				array( 401, array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) ),
				array( 404, array( 'type' => 'error', 'error' => array( 'type' => 'not_found_error', 'message' => 'model: claude-nope' ) ) ),
			)
		);
		foreach ( array( array( 'claude-x', '/rejected the API key: invalid x-api-key/' ), array( 'claude-nope', '/"claude-nope" isn\'t available/' ) ) as [ $model, $re ] ) {
			try {
				( new Anthropic( 'k', $model ) )->session( 'S', 'P' )->ask();
				$this->fail( 'expected LlmException' );
			} catch ( LlmException $e ) {
				$this->assertMatchesRegularExpression( $re, $e->getMessage() );
			}
		}
	}
}
