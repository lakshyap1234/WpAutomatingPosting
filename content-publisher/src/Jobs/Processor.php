<?php
/**
 * Background processing: read the post, structure it with the AI, store the
 * result for review. Runs on Action Scheduler, one post at a time per client
 * site (a per-site database lock), so one client's batch can't hold up
 * another's or flood the AI with parallel requests.
 *
 * Temporary AI problems (rate limits, overload, network) are retried later;
 * anything else fails the job with a message for the staff.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

use CPub\Publisher\Intake\IntakeException;
use CPub\Publisher\Pipeline\FidelityException;
use CPub\Publisher\Pipeline\Ingest;
use CPub\Publisher\Pipeline\Llm\LlmException;
use CPub\Publisher\Pipeline\Pipeline;
use CPub\Publisher\Pipeline\StructureException;
use CPub\Publisher\Pipeline\StructureMap;
use CPub\Publisher\Settings\AiSettings;
use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Processor {

	public const HOOK  = 'cpub_process_job';
	public const GROUP = 'cpub';

	/** Delays (seconds) before retrying a temporary AI problem: then the job fails. */
	public const RETRY_DELAYS = array( 120, 600 );

	/** A job still "processing" after this long was cut off (PHP killed, server restart). */
	public const STALE_AFTER = 900;

	/** Seconds to wait before trying again when the site's lock is taken. */
	public const BUSY_DELAY = 30;

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'process' ) );
		add_action( \CPub\Publisher\Connections\HealthCheck::HOOK, array( self::class, 'recover_stale' ) ); // daily
	}

	/** Put a job in the queue to be processed as soon as possible (or after $delay seconds). */
	public static function queue( int $job_id, int $delay = 0 ): void {
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $job_id ), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK, array( $job_id ), self::GROUP, true );
		}
	}

	/** Action Scheduler entry point. */
	public static function process( $job_id ): void {
		global $wpdb;
		$job = Jobs::get( (int) $job_id );
		if ( ! $job || Jobs::QUEUED !== $job->status ) {
			return; // deleted, or already handled
		}
		$lock = 'cpub_process_' . $job->site_id . '_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 8 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			self::queue( $job->id, self::BUSY_DELAY ); // another post for this site is being processed
			return;
		}
		try {
			if ( ! Jobs::update( $job->id, array( 'status' => Jobs::PROCESSING, 'last_error' => null, 'attempts' => $job->attempts + 1 ), null, array( Jobs::QUEUED ) ) ) {
				return;
			}
			self::run( Jobs::get( $job->id ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function run( object $job ): void {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		try {
			$source = Assets::source( $job->id );
			$bytes  = $source ? Assets::bytes( $source ) : null;
			if ( null === $bytes ) {
				throw new IntakeException( 'The uploaded file is missing from storage.' );
			}
			$result = ( new Pipeline( AiSettings::provider() ) )->run( $bytes, $job->source_name );
			self::store( $job, $result );
		} catch ( LlmException $e ) {
			$tries = (int) ( $job->data['ai_retries'] ?? 0 );
			if ( $e->transient && $tries < count( self::RETRY_DELAYS ) ) {
				$data               = $job->data;
				$data['ai_retries'] = $tries + 1;
				Jobs::update( $job->id, array( 'status' => Jobs::QUEUED, 'last_error' => $e->getMessage(), 'data' => $data ), null, array( Jobs::PROCESSING ) );
				Events::log( 'job_retry', 'The AI service had a temporary problem; trying again in ' . human_time_diff( 0, self::RETRY_DELAYS[ $tries ] ) . '. ' . $e->getMessage(), (int) $job->site_id, $job->id );
				self::queue( $job->id, self::RETRY_DELAYS[ $tries ] );
				return;
			}
			self::fail( $job, $e->getMessage() );
		} catch ( StructureException | FidelityException | IntakeException | \InvalidArgumentException $e ) {
			self::fail( $job, $e->getMessage() );
		} catch ( \Throwable $e ) {
			error_log( 'Content Publisher job ' . $job->id . ': ' . $e ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			self::fail( $job, 'Unexpected error (details are in the PHP error log): ' . $e->getMessage() );
		}
	}

	/** Save a pipeline result for review, including images taken out of a Word file. */
	private static function store( object $job, array $r ): void {
		foreach ( Assets::for_job( $job->id, Assets::IMAGE ) as $old ) {
			if ( 'docx' === $old->origin ) {
				Assets::delete( $old ); // a re-run takes them out again
			}
		}
		$kept = array_map( fn( $a ) => mb_strtolower( (string) $a->filename ), Assets::for_job( $job->id, Assets::IMAGE ) );
		foreach ( (array) ( $r['docx']['images'] ?? array() ) as $img ) {
			if ( ! in_array( mb_strtolower( $img['name'] ), $kept, true ) ) { // a reviewer's replacement wins
				Assets::add( $job, Assets::IMAGE, 'docx', $img['name'], $img['bytes'], $img['mime'] );
			}
		}
		$data = array_merge(
			$job->data,
			array(
				'original'   => array( 'title' => $r['title'], 'body_text' => $r['body_text'] ),
				'removed'    => $r['removed'],
				'usage'      => $r['usage'],
				'ai'         => $r['ai'] ? self::ai_label() : null,
				'encoding'   => $r['encoding'],
				'ai_retries' => 0,
			)
		);
		$ok   = Jobs::update(
			$job->id,
			array(
				'status'        => Jobs::REVIEW,
				'title'         => '' !== trim( $r['title'] ) ? $r['title'] : $job->title,
				'markdown'      => $r['markdown'],
				'structure_map' => $r['map'],
				'warnings'      => $r['warnings'],
				'data'          => $data,
				'last_error'    => null,
			),
			null,
			array( Jobs::PROCESSING )
		);
		if ( $ok ) {
			$how = $r['ai'] ? sprintf( 'Structured by the AI in %d attempt(s).', $r['attempts'] ) : 'Markdown file: used as written.';
			Events::log( 'job_processed', $how . ( $r['warnings'] ? ' ' . count( $r['warnings'] ) . ' note(s) for the reviewer.' : '' ), (int) $job->site_id, $job->id, array( 'usage' => $r['usage'] ) );
		}
	}

	private static function ai_label(): ?string {
		$p = AiSettings::provider();
		return $p ? $p->name() . ' · ' . $p->model() : null;
	}

	private static function fail( object $job, string $message ): void {
		$message = mb_substr( $message, 0, 2000 );
		Jobs::update( $job->id, array( 'status' => Jobs::FAILED, 'last_error' => $message ), null, array( Jobs::PROCESSING ) );
		Events::log( 'job_failed', $message, (int) $job->site_id, $job->id );
	}

	/** Staff: put a failed job back in the queue. */
	public static function retry( object $job ): bool {
		$data               = $job->data;
		$data['ai_retries'] = 0;
		if ( ! Jobs::update( $job->id, array( 'status' => Jobs::QUEUED, 'last_error' => null, 'data' => $data ), null, array( Jobs::FAILED ) ) ) {
			return false;
		}
		Events::log( 'job_retried', 'Put back in the queue.', (int) $job->site_id, $job->id );
		self::queue( $job->id );
		return true;
	}

	/** Reviewer: discard the current structure and run the AI again. */
	public static function rerun( object $job, int $revision ): bool {
		$data               = $job->data;
		$data['ai_retries'] = 0;
		if ( ! Jobs::update( $job->id, array( 'status' => Jobs::QUEUED, 'last_error' => null, 'data' => $data ), $revision, array( Jobs::REVIEW, Jobs::REJECTED, Jobs::FAILED ) ) ) {
			return false;
		}
		Events::log( 'job_rerun', 'Sent back to the AI; the previous version and its edits were discarded.', (int) $job->site_id, $job->id );
		self::queue( $job->id );
		return true;
	}

	/**
	 * When the AI can't structure a post: every paragraph as written (title =
	 * first line), for the reviewer to format by hand. Same fidelity check.
	 */
	public static function plain( object $job ): bool {
		$source = Assets::source( $job->id );
		$bytes  = $source ? Assets::bytes( $source ) : null;
		if ( null === $bytes || 'md' === $job->source_format ) {
			return false;
		}
		try {
			$text     = 'docx' === $job->source_format ? \CPub\Publisher\Intake\Docx::read( $bytes )['text'] : $bytes;
			$prepared = Ingest::ingest( $text );
		} catch ( \Throwable $e ) {
			return false;
		}
		$blocks = array();
		$group  = array();
		foreach ( array_slice( $prepared['layout'], 1 ) as $l ) {
			if ( ! empty( $l['blank'] ) ) {
				if ( $group ) {
					$blocks[] = array( 'type' => 'paragraph', 'lines' => $group );
				}
				$group = array();
			} else {
				$group[] = $l['id'];
			}
		}
		if ( $group ) {
			$blocks[] = array( 'type' => 'paragraph', 'lines' => $group );
		}
		if ( ! $blocks ) {
			return false;
		}
		// Image lines can't sit in paragraphs; the map would be refused. Put each group
		// that is only image lines in an image block, and leave the rest as text.
		foreach ( $blocks as &$b ) {
			$all = true;
			foreach ( $b['lines'] as $id ) {
				$t   = $prepared['lines'][ $id - 1 ]['text'];
				$all = $all && ( \CPub\Publisher\Pipeline\Blocks::looks_like_image_line( $t ) || preg_match( '/^(caption|credit|source)\s*:/i', $t ) );
			}
			if ( $all && \CPub\Publisher\Pipeline\Blocks::parse_image( array_map( fn( $id ) => $prepared['lines'][ $id - 1 ], $b['lines'] ) )['ok'] ) {
				$b['type'] = 'image';
			}
		}
		unset( $b );
		$map  = array( 'title' => 1, 'excluded' => array(), 'blocks' => $blocks );
		$data = $job->data;
		if ( ! Jobs::update( $job->id, array( 'status' => Jobs::PROCESSING ), null, array( Jobs::FAILED ) ) ) {
			return false;
		}
		try {
			$r             = ( new Pipeline() )->run( $bytes, $job->source_name, $map );
			$r['warnings'] = array_merge( array( 'This post was set out without the AI: every paragraph is plain text. Add the headings, lists and tables yourself.' ), $r['warnings'] );
			self::store( Jobs::get( $job->id ), $r );
			Events::log( 'job_plain', 'Set out as plain paragraphs, without the AI.', (int) $job->site_id, $job->id );
			return true;
		} catch ( \Throwable $e ) {
			Jobs::update( $job->id, array( 'status' => Jobs::FAILED, 'last_error' => 'Could not set it out as plain paragraphs either: ' . $e->getMessage(), 'data' => $data ), null, array( Jobs::PROCESSING ) );
			return false;
		}
	}

	/**
	 * Jobs left "processing" by a request that was cut off go back in the queue
	 * once, then fail. Called when the queue page loads, and daily.
	 */
	public static function recover_stale(): int {
		global $wpdb;
		$table = \CPub\Publisher\Installer::table( 'jobs' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND updated_at < %s", Jobs::PROCESSING, gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $ids as $id ) {
			$job = Jobs::get( (int) $id );
			if ( ! $job ) {
				continue;
			}
			if ( empty( $job->data['cut_off'] ) ) {
				$data            = $job->data;
				$data['cut_off'] = 1;
				if ( Jobs::update( $job->id, array( 'status' => Jobs::QUEUED, 'data' => $data ), null, array( Jobs::PROCESSING ) ) ) {
					Events::log( 'job_retry', 'Processing stopped unexpectedly (the server may have cut it off); trying once more.', (int) $job->site_id, $job->id );
					self::queue( $job->id );
				}
			} else {
				self::fail( $job, 'Processing stopped unexpectedly twice. The server may be limiting how long a request can run; try a shorter post, or ask the host to raise the limit.' );
			}
		}
		// Sending cut off: every step is safe to repeat, so try once more, then report it.
		$sending = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND updated_at < %s", Jobs::SENDING, gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $sending as $id ) {
			$job = Jobs::get( (int) $id );
			if ( ! $job ) {
				continue;
			}
			$data = $job->data;
			if ( empty( $data['send_cut_off'] ) ) {
				$data['send_cut_off'] = 1;
				if ( Jobs::update( $job->id, array( 'status' => Jobs::APPROVED, 'data' => $data ), null, array( Jobs::SENDING ) ) ) {
					Events::log( 'send_retry', 'Sending stopped unexpectedly (the server may have cut it off); trying once more.', (int) $job->site_id, $job->id );
					Sender::queue( $job->id );
				}
			} elseif ( Jobs::update( $job->id, array( 'status' => Jobs::SEND_FAILED, 'last_error' => 'Sending stopped unexpectedly twice. The server may be limiting how long a request can run.' ), null, array( Jobs::SENDING ) ) ) {
				Events::log( 'send_failed', 'Sending stopped unexpectedly twice.', (int) $job->site_id, $job->id );
			}
		}
		// Approved with no send scheduled (the queue lost the action): schedule it again.
		$approved = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND updated_at < %s", Jobs::APPROVED, gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $approved as $id ) {
			if ( ! as_has_scheduled_action( Sender::HOOK, array( (int) $id ), Sender::GROUP ) ) {
				Sender::queue( (int) $id );
			}
		}
		// Queued with nothing scheduled to run it (the queue lost the action): schedule it again.
		$queued = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s AND updated_at < %s", Jobs::QUEUED, gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $queued as $id ) {
			if ( ! as_has_scheduled_action( self::HOOK, array( (int) $id ), self::GROUP ) ) {
				self::queue( (int) $id );
			}
		}
		return count( $ids );
	}
}
