<?php
/**
 * Shared set-up for job tests: a connected client site, an editor, and an AI provider the test controls.
 */

use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Installer;
use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Intake\IntakeException;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Pipeline\Llm\LlmException;
use CPub\Publisher\Pipeline\Llm\Provider;
use CPub\Publisher\Pipeline\Llm\Session;
use CPub\Publisher\Support\Events;

abstract class JobsTestCase extends PipelineTestCase {

	protected int $site;
	protected int $user;
	/** @var ?object provider the filter hands out */
	protected $provider = null;

	public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	public function set_up() {
		parent::set_up();
		Installer::activate();
		$this->site = Sites::create( 'https://client.example', 'Client' );
		Sites::update( $this->site, array( 'status' => Sites::CONNECTED ) );
		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->user );
		add_filter( 'cpub_publisher_ai_provider', fn() => $this->provider );
	}

	protected static function file( string $sample ): array {
		return array( 'name' => $sample, 'bytes' => (string) file_get_contents( dirname( __DIR__ ) . '/samples/' . $sample ) );
	}

	protected static function png( string $name ): array {
		return array( 'name' => $name, 'bytes' => base64_decode( self::PNG ) );
	}

	protected function scheduled( int $job_id ): bool {
		return as_has_scheduled_action( Processor::HOOK, array( $job_id ), Processor::GROUP );
	}

	protected function upload( string $sample ): object {
		return Jobs::get( Intake::submit( $this->site, array( self::file( $sample ) ), array(), $this->user )['created'][0] );
	}

	protected static function docx_map(): array {
		return array(
			'title'    => 1,
			'excluded' => array(),
			'blocks'   => array(
				array( 'type' => 'paragraph', 'lines' => range( 2, 5 ) ),
				array( 'type' => 'list', 'ordered' => false, 'items' => array( array( 6 ), array( 7 ) ) ),
				array( 'type' => 'paragraph', 'lines' => array( 8 ) ),
				array( 'type' => 'table', 'header' => true, 'lines' => array( 9, 10, 11 ) ),
				array( 'type' => 'image', 'lines' => array( 12, 13, 14 ) ),
				array( 'type' => 'paragraph', 'lines' => range( 15, 23 ) ),
			),
		);
	}

}
