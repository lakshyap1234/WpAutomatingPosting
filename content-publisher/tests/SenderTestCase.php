<?php
/**
 * Shared fixture for the sending tests: a connected (fake) client site, a
 * stand-in for web images, and approved posts ready to send.
 */

use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Connections\SiteSettings;
use CPub\Publisher\Intake\Intake;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Jobs\Review;
use CPub\Publisher\Jobs\Sender;
use CPub\Publisher\Support\Events;

abstract class SenderTestCase extends ConnectionsTestCase {

	protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	protected object $site;
	/** @var array<string, array> web image url => [status, body, headers?] */
	protected array $web = array();
	protected array $downloads = array();

	public function set_up(): void {
		parent::set_up();
		$this->site = $this->connect();
		$this->web  = array( 'https://images.example/cup.png' => array( 200, base64_decode( self::PNG ) ) );
		// images.example doesn't resolve; say it's a public host so the address check lets it through.
		add_filter( 'http_request_host_is_external', fn( $external, $host ) => $external || 'images.example' === $host, 10, 2 );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== $pre || ! str_starts_with( $url, 'https://images.example/' ) ) {
					return $pre;
				}
				$this->downloads[] = $url;
				$r                 = $this->web[ $url ] ?? array( 404, 'nope' );
				return 'timeout' === $r[0] ? new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) : array( 'headers' => $r[2] ?? array(), 'body' => $r[1], 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
			},
			5,
			3
		);
	}

	/** An approved job with an uploaded image file and a web image. */
	protected function approved( string $markdown = '', array $removed = array() ): object {
		$markdown = '' !== $markdown ? $markdown : "# Coffee at home\n\nA **good** grinder matters.\n\n![A cup](cup-file.png \"Morning cup\")\n\n![From the web](https://images.example/cup.png)\n\nEnd.\n";
		$r        = Intake::submit( (int) $this->site->id, array( array( 'name' => 'p.md', 'bytes' => $markdown ) ), array( array( 'name' => 'cup-file.png', 'bytes' => base64_decode( self::PNG ) ) ), $this->admin );
		$id       = $r['created'][0];
		as_unschedule_all_actions( Processor::HOOK, array( $id ), Processor::GROUP );
		$job  = Jobs::get( $id );
		$data = $job->data;
		$data['removed'] = $removed;
		Jobs::update( $id, array( 'status' => Jobs::APPROVED, 'markdown' => $markdown, 'data' => $data ) );
		return Jobs::get( $id );
	}

	protected static function last_event( int $job_id ): string {
		$all = Events::for_job( $job_id );
		return (string) end( $all )->message;
	}

	protected function scheduled( int $id ): ?int {
		$t = as_next_scheduled_action( Sender::HOOK, array( $id ), Sender::GROUP );
		return is_int( $t ) ? $t : ( true === $t ? time() : null );
	}
}
