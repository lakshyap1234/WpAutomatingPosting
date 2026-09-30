<?php
/**
 * What a reviewer can see and do with a post: preview, save, approve, reject,
 * reopen, images. Every change names the revision it was based on, so two
 * reviewers can't silently overwrite each other.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Jobs;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Connections\Sites;
use CPub\Publisher\Pipeline\MarkdownToPost;
use CPub\Publisher\Pipeline\MarkdownWriter;
use CPub\Publisher\Support\Events;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Review {

	/** Markdown size the editor accepts: the parser needs up to ~700 bytes of memory per byte of symbol-dense text. */
	public const MAX_MARKDOWN = 262144;

	/** Statuses in which images can be added or removed. */
	public const IMAGE_STATES = array( Jobs::REVIEW, Jobs::REJECTED, Jobs::FAILED, Jobs::QUEUED );

	/** Statuses in which the Markdown can be edited. */
	public const EDITABLE = array( Jobs::REVIEW );

	/**
	 * The job as the editor needs it.
	 *
	 * @return array<string,mixed>
	 */
	public static function view( object $job ): array {
		$site   = Sites::get( (int) $job->site_id );
		$images = Assets::for_job( $job->id, Assets::IMAGE );
		$used   = self::used_images( $job, $images );
		$user   = fn( $id ) => $id ? ( get_userdata( (int) $id )->display_name ?? 'Unknown user' ) : '';
		return array(
			'id'          => $job->id,
			'status'      => $job->status,
			'status_text' => Jobs::label( $job ),
			'revision'    => $job->revision,
			'title'       => $job->title,
			'source_name' => $job->source_name,
			'format'      => $job->source_format,
			'site'        => $site ? array(
				'id'          => (int) $site->id,
				'name'        => $site->name ?: $site->url,
				'url'         => $site->url,
				'settings'    => \CPub\Publisher\Connections\SiteSettings::get( $site ),
				'can_publish' => ! empty( $site->can_publish ),
				'can_create_tags' => Sender::can_create_tags( $site ),
				'author'      => (string) $site->remote_user,
			) : null,
			'target'      => $site ? Sender::target( $job, $site ) : null,
			'tags'        => Sender::wanted_tags( $job ),
			'publish_at'  => (string) ( $job->data['publish_at'] ?? '' ),
			'remote_post_status' => (string) $job->remote_post_status,
			'files_deleted' => $job->data['files_deleted'] ?? null,
			'markdown'    => (string) $job->markdown,
			'original'    => $job->data['original'] ?? null,
			'removed'     => array_map( fn( $r ) => $r + array( 'markdown' => MarkdownWriter::line( (string) $r['text'] ) ), (array) ( $job->data['removed'] ?? array() ) ),
			// The "no Image URL" note is moot once a file with that name is here.
			'warnings'    => array_values( array_filter( $job->warnings, fn( $w ) => ! preg_match( '/^Image "([^"]+)" on line \d+ has no Image URL/u', $w, $m ) || ! ImageMatcher::find( $m[1], $images ) ) ),
			'last_error'  => (string) $job->last_error,
			'review_note' => (string) $job->review_note,
			'ai'          => $job->data['ai'] ?? null,
			'sent'        => $job->data['sent'] ?? null,
			'published'   => isset( $job->data['published_at'] ) ? array( 'at' => $job->data['published_at'], 'link' => $job->data['published_link'] ?? '' ) : null,
			'client_deleted' => $job->data['client_deleted'] ?? null,
			'remote_post_id' => (int) $job->remote_post_id,
			'sending_note'   => Jobs::APPROVED === $job->status && $job->last_error ? 'Waiting to retry: ' . $job->last_error : '',
			'uploaded_by' => $user( $job->created_by ),
			'reviewed_by' => $user( $job->reviewed_by ?? 0 ),
			'created_at'  => mysql_to_rfc3339( (string) $job->created_at ),
			'updated_at'  => mysql_to_rfc3339( (string) $job->updated_at ),
			'images'      => array_map(
				fn( $a ) => array(
					'id'       => (int) $a->id,
					'filename' => $a->filename,
					'origin'   => $a->origin,
					'size'     => (int) $a->size,
					'url'      => AssetController::url( $job->id, (int) $a->id ),
					'used'     => isset( $used[ (int) $a->id ] ),
				),
				$images
			),
			'history'     => array_map(
				fn( $e ) => array(
					'type'    => $e->type,
					'message' => (string) $e->message,
					'user'    => $user( $e->user_id ),
					'at'      => mysql_to_rfc3339( (string) $e->created_at ),
				),
				\CPub\Publisher\Support\Events::for_job( $job->id )
			),
			'can'         => self::can( $job ),
		);
	}

	/** What can be done with the job in its current state. @return array<string,bool> */
	public static function can( object $job ): array {
		$site    = Sites::get( (int) $job->site_id );
		$publish = $site && ! empty( $site->can_publish );
		$live    = in_array( (string) $job->remote_post_status, Sender::LIVE, true );
		return array(
			'edit'    => in_array( $job->status, self::EDITABLE, true ),
			'approve' => Jobs::REVIEW === $job->status,
			'reject'  => Jobs::REVIEW === $job->status,
			'reopen'  => in_array( $job->status, self::reopenable( $job, $publish ), true ),
			'unpublish' => $publish && $job->remote_post_id && $live && in_array( $job->status, array( Jobs::PUBLISHED, Jobs::SENT ), true ),
			'send'    => Jobs::SEND_FAILED === $job->status || ( Jobs::SENT === $job->status && ! empty( $job->data['client_deleted'] ) ),
			'images'  => in_array( $job->status, self::IMAGE_STATES, true ),
			'check'   => Jobs::SENT === $job->status,
			'rerun'   => in_array( $job->status, array( Jobs::REVIEW, Jobs::REJECTED, Jobs::FAILED ), true ) && 'md' !== $job->source_format,
			'retry'   => Jobs::FAILED === $job->status,
			'plain'   => Jobs::FAILED === $job->status && 'md' !== $job->source_format,
			'delete'  => ! in_array( $job->status, array( Jobs::APPROVED, Jobs::PROCESSING, Jobs::SENDING ), true ),
		);
	}

	/**
	 * States a post can go back to review from: approved (until sending starts),
	 * rejected, failed sends, sent drafts; published ones only when the site lets
	 * the agency correct them.
	 *
	 * @return string[]
	 */
	private static function reopenable( object $job, bool $publish ): array {
		$from = array( Jobs::APPROVED, Jobs::REJECTED, Jobs::SEND_FAILED, Jobs::SENT );
		if ( $publish && $job->remote_post_id ) {
			$from[] = Jobs::PUBLISHED;
		}
		return $from;
	}

	/**
	 * The editor's choices besides the text: tags, and a publish date.
	 *
	 * @param array{tags?:mixed, publish_at?:mixed} $opts
	 * @return array|\WP_Error the job data with them set
	 */
	private static function with_options( object $job, array $opts ) {
		$data = $job->data;
		if ( array_key_exists( 'tags', $opts ) && null !== $opts['tags'] ) {
			$tags = array();
			foreach ( (array) $opts['tags'] as $t ) {
				$t = trim( sanitize_text_field( (string) $t ) );
				if ( '' !== $t && ! isset( $tags[ mb_strtolower( $t ) ] ) ) {
					$tags[ mb_strtolower( $t ) ] = mb_substr( $t, 0, 100 );
				}
			}
			if ( count( $tags ) > 20 ) {
				return new \WP_Error( 'cpub_tags', 'A post can have at most 20 tags.', array( 'status' => 400 ) );
			}
			$data['tags'] = array_values( $tags );
		}
		if ( array_key_exists( 'publish_at', $opts ) && null !== $opts['publish_at'] ) {
			$at = trim( (string) $opts['publish_at'] );
			if ( '' === $at ) {
				unset( $data['publish_at'] );
			} else {
				$ts = strtotime( $at );
				if ( ! $ts ) {
					return new \WP_Error( 'cpub_publish_at', 'The publish date isn\'t a date.', array( 'status' => 400 ) );
				}
				$data['publish_at'] = gmdate( 'c', $ts );
			}
		}
		return $data;
	}

	/** @return array<int,true> ids of images the current Markdown uses */
	private static function used_images( object $job, array $images ): array {
		$used = array();
		foreach ( MarkdownToPost::convert( (string) $job->markdown, array( 'format' => 'html' ) )['images'] as $img ) {
			$hit = ImageMatcher::find( (string) $img['src'], $images );
			if ( $hit ) {
				$used[ (int) $hit->id ] = true;
			}
		}
		return $used;
	}

	/**
	 * Convert Markdown the way it will be sent, with the post's image files
	 * standing in for images given by file name.
	 *
	 * @return array{title:string, html:string, errors:array, plain_text:string, counts:array, images:array}
	 */
	public static function preview( object $job, string $markdown ): array {
		$images = Assets::for_job( $job->id, Assets::IMAGE );
		wp_raise_memory_limit( 'admin' );
		$first  = MarkdownToPost::convert( $markdown, array( 'format' => 'html' ) );
		$local  = array();
		$sent   = (array) ( $job->data['media'] ?? array() );
		foreach ( $first['images'] as $img ) {
			$hit = ImageMatcher::find( (string) $img['src'], $images );
			if ( $hit ) {
				$local[ $img['src'] ] = AssetController::url( $job->id, (int) $hit->id );
			} elseif ( ! empty( $sent[ $img['src'] ]['purged'] ) && ! empty( $sent[ $img['src'] ]['src'] ) ) {
				// Our copy was deleted after the retention period; the client site has it.
				$local[ $img['src'] ] = (string) $sent[ $img['src'] ]['src'];
			}
		}
		$post = $local ? MarkdownToPost::convert( $markdown, array( 'format' => 'html', 'local' => $local ) ) : $first;
		return array(
			'title'      => $post['title'],
			'html'       => wp_kses_post( $post['content'] ),
			'errors'     => $post['errors'],
			'plain_text' => $post['plain_text'],
			'counts'     => $post['counts'],
			'images'     => array_map( fn( $i ) => array( 'src' => rawurldecode( (string) $i['src'] ), 'file' => (bool) $i['file'], 'line' => $i['line'] ), $post['images'] ),
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function save( object $job, string $markdown, int $revision, array $opts = array() ) {
		if ( ! in_array( $job->status, self::EDITABLE, true ) ) {
			return new \WP_Error( 'cpub_not_editable', 'This post can\'t be edited in its current state (' . ( Jobs::LABELS[ $job->status ] ?? $job->status ) . ').', array( 'status' => 409 ) );
		}
		if ( strlen( $markdown ) > self::MAX_MARKDOWN ) {
			return new \WP_Error( 'cpub_too_long', 'The post is too long.', array( 'status' => 413 ) );
		}
		$data = self::with_options( $job, $opts );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		if ( $markdown === (string) $job->markdown && $data === $job->data ) {
			return $revision === $job->revision ? true : self::conflict( $job );
		}
		$title = MarkdownToPost::convert( $markdown, array( 'format' => 'html' ) )['title'];
		if ( ! Jobs::update( $job->id, array( 'markdown' => $markdown, 'title' => '' !== $title ? $title : $job->title, 'data' => $data ), $revision, self::EDITABLE ) ) {
			return self::conflict( Jobs::get( $job->id ) ?? $job );
		}
		Events::log( 'job_edited', 'Edited.', (int) $job->site_id, $job->id );
		return true;
	}

	/** @return true|\WP_Error */
	public static function approve( object $job, string $markdown, int $revision, array $opts = array() ) {
		$saved = self::save( $job, $markdown, $revision, $opts );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$job    = Jobs::get( $job->id );
		$errors = self::preview( $job, (string) $job->markdown )['errors'];
		if ( $errors ) {
			return new \WP_Error( 'cpub_has_problems', 'Fix the problems listed under the editor before approving.', array( 'status' => 422, 'problems' => $errors ) );
		}
		$data                 = $job->data;
		$data['send_retries'] = 0; // a new approval starts sending afresh
		$data['send_cut_off'] = 0;
		$site                 = Sites::get( (int) $job->site_id );
		// What the reviewer approved: sending never goes further (Sender::send_target).
		$data['approved_target'] = $site ? Sender::target( $job, $site ) : null;
		if ( ! Jobs::update( $job->id, array( 'status' => Jobs::APPROVED, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => gmdate( 'Y-m-d H:i:s' ), 'review_note' => null, 'last_error' => null, 'data' => $data ), $job->revision, array( Jobs::REVIEW ) ) ) {
			return self::conflict( Jobs::get( $job->id ) ?? $job );
		}
		$target = $data['approved_target'];
		Events::log( 'job_approved', 'Approved. ' . ( $target ? $target['label'] . '.' : 'Sending it to the client site.' ), (int) $job->site_id, $job->id );
		as_unschedule_all_actions( Sender::HOOK, array( $job->id ), Sender::GROUP ); // an old retry waiting its turn
		Sender::queue( $job->id );
		return true;
	}

	/** @return true|\WP_Error */
	public static function reject( object $job, string $reason, int $revision ) {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			return new \WP_Error( 'cpub_reason', 'Say why the post is rejected, so the team knows what to do.', array( 'status' => 400 ) );
		}
		if ( ! Jobs::update( $job->id, array( 'status' => Jobs::REJECTED, 'review_note' => mb_substr( $reason, 0, 2000 ), 'reviewed_by' => get_current_user_id(), 'reviewed_at' => gmdate( 'Y-m-d H:i:s' ) ), $revision, array( Jobs::REVIEW ) ) ) {
			return self::conflict( Jobs::get( $job->id ) ?? $job );
		}
		Events::log( 'job_rejected', 'Rejected: ' . $reason, (int) $job->site_id, $job->id );
		return true;
	}

	/** Back to review: rejected posts, and approved ones until sending starts (or after it failed). @return true|\WP_Error */
	public static function reopen( object $job, int $revision ) {
		$site = Sites::get( (int) $job->site_id );
		if ( ! Jobs::update( $job->id, array( 'status' => Jobs::REVIEW ), $revision, self::reopenable( $job, $site && ! empty( $site->can_publish ) ) ) ) {
			return self::conflict( Jobs::get( $job->id ) ?? $job );
		}
		Events::log( 'job_reopened', 'Reopened for review.', (int) $job->site_id, $job->id );
		return true;
	}

	/**
	 * Add or replace an image file (by name) during review.
	 *
	 * @return object|\WP_Error the asset
	 */
	public static function add_image( object $job, string $name, string $bytes ) {
		if ( ! in_array( $job->status, self::IMAGE_STATES, true ) ) {
			return new \WP_Error( 'cpub_not_editable', 'Images can\'t be changed in this state.', array( 'status' => 409 ) );
		}
		$name = mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', basename( str_replace( '\\', '/', $name ) ) ), 0, 200 );
		if ( strlen( $bytes ) > Assets::MAX_IMAGE_BYTES ) {
			return new \WP_Error( 'cpub_image', 'The image is larger than 10 MB.', array( 'status' => 413 ) );
		}
		$type = Assets::sniff_image( $bytes );
		if ( ! $type || '' === trim( $name ) ) {
			return new \WP_Error( 'cpub_image', 'Not a JPEG, PNG, GIF or WebP image.', array( 'status' => 415 ) );
		}
		foreach ( Assets::for_job( $job->id, Assets::IMAGE ) as $old ) {
			if ( mb_strtolower( $old->filename ) === mb_strtolower( $name ) ) {
				Assets::delete( $old );
			}
		}
		$id = Assets::add( $job, Assets::IMAGE, 'editor', $name, $bytes, $type['mime'] );
		Jobs::update( $job->id, array() ); // new revision: an approval must have seen the images as they are now
		Events::log( 'image_added', sprintf( 'Added image %s.', $name ), (int) $job->site_id, $job->id );
		return Assets::get( $id );
	}

	public static function delete_image( object $job, object $asset ): void {
		Assets::delete( $asset );
		Jobs::update( $job->id, array() );
		Events::log( 'image_removed', sprintf( 'Removed image %s.', $asset->filename ), (int) $job->site_id, $job->id );
	}

	/** @return true|\WP_Error */
	public static function delete( object $job ) {
		if ( ! self::can( $job )['delete'] ) {
			return new \WP_Error( 'cpub_not_deletable', 'A post that is being processed, waiting to send or sending can\'t be deleted.', array( 'status' => 409 ) );
		}
		if ( ! Jobs::delete( $job->id, array( Jobs::APPROVED, Jobs::PROCESSING, Jobs::SENDING ) ) ) {
			return new \WP_Error( 'cpub_not_deletable', 'The post changed state meanwhile (it may be processing now). Reload and try again.', array( 'status' => 409 ) );
		}
		Events::log( 'job_deleted', sprintf( 'Deleted %s (%s).', $job->title, $job->source_name ), (int) $job->site_id, $job->id );
		return true;
	}

	private static function conflict( object $job ): \WP_Error {
		$last = array_reverse( \CPub\Publisher\Support\Events::for_job( $job->id ) )[0] ?? null;
		$who  = $last && $last->user_id && (int) $last->user_id !== get_current_user_id() ? ( get_userdata( (int) $last->user_id )->display_name ?? '' ) : '';
		return new \WP_Error(
			'cpub_conflict',
			'This post was changed' . ( '' !== $who ? " by {$who}" : '' ) . ' since you opened it. Reload to see the latest version; copy your changes first if you need them.',
			array( 'status' => 409, 'revision' => $job->revision, 'job_status' => $job->status )
		);
	}

	public static function can_review(): bool {
		return current_user_can( Capabilities::REVIEW );
	}
}
