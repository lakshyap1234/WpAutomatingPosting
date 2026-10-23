<?php
/**
 * Proposed changes to the site's existing posts and pages (the proposals:write scope).
 *
 * The agency suggests a new title, content or excerpt for one of the site's
 * published posts or pages. The suggestion is stored here, in the Connector's
 * own table, and the post itself is never touched until one of the site's
 * editors applies it (Admin\ProposalsPage). Applying is done as that editor,
 * never through a token, and saves a normal WordPress revision, so it can be
 * undone from the post's revisions.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector;

use CPub\Connector\Auth\BearerAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Proposals {

	public const PENDING    = 'pending';
	public const APPLIED    = 'applied';
	public const REJECTED   = 'rejected';
	public const SUPERSEDED = 'superseded'; // a newer suggestion for the same post arrived

	/** What a proposal may change. */
	public const FIELDS = array( 'title', 'content', 'excerpt' );

	/** post type => what it is called on screen */
	public const TYPES = array(
		'post' => 'post',
		'page' => 'page',
	);

	public const MAX_PENDING = 200;
	public const MAX_TITLE   = 1000;
	public const MAX_CONTENT = 1048576; // 1 MB
	public const MAX_NOTE    = 2000;

	/** The post a proposal may be for: one of the site's published posts or pages, without a password. */
	public static function target( int $post_id ): ?\WP_Post {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		return $post && isset( self::TYPES[ $post->post_type ] ) && 'publish' === $post->post_status && '' === $post->post_password ? $post : null;
	}

	/**
	 * What the proposal would set, cleaned the way WordPress cleans a post saved by
	 * someone without unfiltered_html (whoever applies it later): no scripts, no
	 * event handlers. Only the fields that were sent; null for a field left as is.
	 *
	 * @param array<string,mixed> $sent
	 * @return array{title:?string, content:?string, excerpt:?string}|\WP_Error
	 */
	public static function clean( array $sent ) {
		$out = array_fill_keys( self::FIELDS, null );
		if ( isset( $sent['title'] ) ) {
			$out['title'] = trim( wp_strip_all_tags( (string) $sent['title'] ) );
			if ( '' === $out['title'] || mb_strlen( $out['title'] ) > self::MAX_TITLE ) {
				return new \WP_Error( 'cpub_proposal_invalid', 'The title must be 1 to ' . self::MAX_TITLE . ' characters.', array( 'status' => 400 ) );
			}
		}
		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( isset( $sent[ $field ] ) ) {
				if ( strlen( (string) $sent[ $field ] ) > self::MAX_CONTENT ) {
					return new \WP_Error( 'cpub_proposal_invalid', 'The ' . $field . ' is too long (1 MB at most).', array( 'status' => 400 ) );
				}
				$out[ $field ] = wp_kses_post( (string) $sent[ $field ] );
			}
		}
		if ( '' === trim( (string) $out['content'] ) && null !== $out['content'] ) {
			return new \WP_Error( 'cpub_proposal_invalid', 'The content can\'t be empty.', array( 'status' => 400 ) );
		}
		if ( array( null, null, null ) === array_values( $out ) ) {
			return new \WP_Error( 'cpub_proposal_invalid', 'Send at least one of: ' . implode( ', ', self::FIELDS ) . '.', array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * Stores a suggestion. An older pending suggestion for the same post is
	 * replaced (marked superseded), so editors only see the latest.
	 *
	 * @param array{title:?string, content:?string, excerpt:?string} $changes from clean()
	 * @return int|\WP_Error the proposal ID
	 */
	public static function create( int $grant_id, \WP_Post $post, array $changes ) {
		global $wpdb;
		$table = Installer::table( 'proposals' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state = %s AND post_id <> %d", self::PENDING, $post->ID ) );
		if ( $pending >= self::MAX_PENDING ) {
			return new \WP_Error( 'cpub_proposal_limit', 'This site already has ' . self::MAX_PENDING . ' suggestions waiting for its editors.', array( 'status' => 429 ) );
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET state = %s, decided_at = %s WHERE post_id = %d AND state = %s", self::SUPERSEDED, $now, $post->ID, self::PENDING ) );
		// phpcs:enable
		$wpdb->insert(
			$table,
			array(
				'grant_id'          => $grant_id,
				'post_id'           => $post->ID,
				'state'             => self::PENDING,
				'title'             => $changes['title'],
				'content'           => $changes['content'],
				'excerpt'           => $changes['excerpt'],
				'base_hash'         => self::hash( $post ),
				'created_at'        => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new \WP_Error( 'cpub_proposal_failed', 'The suggestion could not be saved.', array( 'status' => 500 ) );
		}
		ActivityLog::event( $grant_id, 'proposal_received', sprintf( 'Suggested changes (%s) to the %s "%s" (%d), waiting for an editor.', implode( ', ', self::changed( (object) $changes ) ), self::TYPES[ $post->post_type ], $post->post_title, $post->ID ) );
		return $id;
	}

	public static function get( int $id ): ?object {
		global $wpdb;
		$table = Installer::table( 'proposals' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Newest first. $states null: all of them.
	 *
	 * @param string[]|null $states
	 * @return object[]
	 */
	public static function find( ?array $states = null, int $post_id = 0, int $limit = 100 ): array {
		global $wpdb;
		$table = Installer::table( 'proposals' );
		$where = array( '1=1' );
		$args  = array();
		if ( $states ) {
			$where[] = 'state IN (' . implode( ',', array_fill( 0, count( $states ), '%s' ) ) . ')';
			$args    = array_merge( $args, $states );
		}
		if ( $post_id > 0 ) {
			$where[] = 'post_id = %d';
			$args[]  = $post_id;
		}
		$args[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $args ) ) ?: array();
	}

	public static function count_pending(): int {
		global $wpdb;
		$table = Installer::table( 'proposals' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state = %s", self::PENDING ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return string[] the fields this proposal changes */
	public static function changed( object $proposal ): array {
		return array_values( array_filter( self::FIELDS, fn( $f ) => null !== ( $proposal->$f ?? null ) ) );
	}

	/** Whether the post was edited after the suggestion was made (applying would replace those edits). */
	public static function is_stale( object $proposal, \WP_Post $post ): bool {
		return ! hash_equals( (string) $proposal->base_hash, self::hash( $post ) );
	}

	/** The fields a proposal can change, as they are now. */
	private static function hash( \WP_Post $post ): string {
		return hash( 'sha256', $post->post_title . "\0" . $post->post_content . "\0" . $post->post_excerpt );
	}

	/**
	 * Applies a pending suggestion as $user_id: one of the site's editors, on the
	 * Proposed changes screen. Never through an agency token.
	 *
	 * @return true|\WP_Error
	 */
	public static function apply( object $proposal, int $user_id ) {
		if ( BearerAuth::grant() ) {
			return new \WP_Error( 'cpub_proposal_token', 'Suggestions can only be applied by the site\'s own editors.', array( 'status' => 403 ) );
		}
		if ( self::PENDING !== $proposal->state ) {
			return new \WP_Error( 'cpub_proposal_decided', 'This suggestion was already ' . $proposal->state . '.' );
		}
		$post = self::target( (int) $proposal->post_id );
		if ( ! $post ) {
			return new \WP_Error( 'cpub_proposal_gone', 'The original is no longer a published post or page, so the suggestion can\'t be applied.' );
		}
		if ( ! user_can( $user_id, 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'cpub_proposal_cannot', 'You can\'t edit this ' . self::TYPES[ $post->post_type ] . '.' );
		}
		$update = array( 'ID' => $post->ID );
		if ( null !== $proposal->title ) {
			$update['post_title'] = $proposal->title;
		}
		if ( null !== $proposal->content ) {
			$update['post_content'] = wp_kses_post( $proposal->content );
		}
		if ( null !== $proposal->excerpt ) {
			$update['post_excerpt'] = wp_kses_post( $proposal->excerpt );
		}
		// Claimed first, so two editors applying at once can't both write the post.
		if ( ! self::decide( $proposal, self::APPLIED, $user_id ) ) {
			return new \WP_Error( 'cpub_proposal_decided', 'Someone else decided on this suggestion a moment ago.' );
		}
		$result = wp_update_post( wp_slash( $update ), true );
		if ( is_wp_error( $result ) ) {
			self::undecide( $proposal );
			return $result;
		}
		ActivityLog::event( (int) $proposal->grant_id, 'proposal_applied', sprintf( 'Suggested changes applied to the %s "%s" (%d).', self::TYPES[ $post->post_type ], get_the_title( $post->ID ), $post->ID ), $user_id );
		return true;
	}

	/** @return true|\WP_Error */
	public static function reject( object $proposal, int $user_id, string $note = '' ) {
		if ( self::PENDING !== $proposal->state ) {
			return new \WP_Error( 'cpub_proposal_decided', 'This suggestion was already ' . $proposal->state . '.' );
		}
		$note = mb_substr( trim( $note ), 0, self::MAX_NOTE );
		if ( ! self::decide( $proposal, self::REJECTED, $user_id, $note ) ) {
			return new \WP_Error( 'cpub_proposal_decided', 'Someone else decided on this suggestion a moment ago.' );
		}
		ActivityLog::event( (int) $proposal->grant_id, 'proposal_rejected', sprintf( 'Suggested changes to post %d rejected.', (int) $proposal->post_id ) . ( '' !== $note ? ' Reason: ' . $note : '' ), $user_id );
		return true;
	}

	/** Moves a pending proposal to $state. False if it was no longer pending. */
	private static function decide( object $proposal, string $state, int $user_id, string $note = '' ): bool {
		global $wpdb;
		return 1 === $wpdb->update(
			Installer::table( 'proposals' ),
			array( 'state' => $state, 'decided_at' => current_time( 'mysql', true ), 'decided_by' => $user_id, 'note' => $note ),
			array( 'id' => (int) $proposal->id, 'state' => self::PENDING )
		);
	}

	private static function undecide( object $proposal ): void {
		global $wpdb;
		$wpdb->update( Installer::table( 'proposals' ), array( 'state' => self::PENDING, 'decided_at' => null, 'decided_by' => 0 ), array( 'id' => (int) $proposal->id ) );
	}
}
