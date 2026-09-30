<?php
/**
 * REST API for the review editor (cpub/v1). Logged-in staff with the review
 * permission only; WordPress's REST nonce protects against cross-site requests.
 *
 * @package CPub\Publisher
 */

namespace CPub\Publisher\Rest;

use CPub\Publisher\Capabilities;
use CPub\Publisher\Jobs\Assets;
use CPub\Publisher\Jobs\Jobs;
use CPub\Publisher\Jobs\Processor;
use CPub\Publisher\Jobs\Review;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobsController {

	public const NS = 'cpub/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$id   = '/jobs/(?P<id>\d+)';
		$perm = array( self::class, 'permission' );
		$rev  = array( 'revision' => array( 'type' => 'integer', 'required' => true ) );
		$md   = array( 'markdown' => array( 'type' => 'string', 'required' => true ) );
		$opts = array(
			'tags'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'publish_at' => array( 'type' => 'string' ),
		);

		register_rest_route( self::NS, $id, array(
			array( 'methods' => 'GET', 'callback' => array( self::class, 'get' ), 'permission_callback' => $perm ),
			array( 'methods' => 'PUT', 'callback' => array( self::class, 'save' ), 'permission_callback' => $perm, 'args' => $md + $rev + $opts ),
			array( 'methods' => 'DELETE', 'callback' => array( self::class, 'delete' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NS, $id . '/preview', array( 'methods' => 'POST', 'callback' => array( self::class, 'preview' ), 'permission_callback' => $perm, 'args' => $md ) );
		register_rest_route( self::NS, $id . '/approve', array( 'methods' => 'POST', 'callback' => array( self::class, 'approve' ), 'permission_callback' => $perm, 'args' => $md + $rev + $opts ) );
		register_rest_route( self::NS, $id . '/unpublish', array( 'methods' => 'POST', 'callback' => array( self::class, 'unpublish' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/sites/(?P<site>\d+)/tags', array( 'methods' => 'GET', 'callback' => array( self::class, 'site_tags' ), 'permission_callback' => $perm, 'args' => array( 'search' => array( 'type' => 'string', 'default' => '' ) ) ) );
		register_rest_route( self::NS, $id . '/reject', array( 'methods' => 'POST', 'callback' => array( self::class, 'reject' ), 'permission_callback' => $perm, 'args' => $rev + array( 'reason' => array( 'type' => 'string', 'required' => true ) ) ) );
		register_rest_route( self::NS, $id . '/reopen', array( 'methods' => 'POST', 'callback' => array( self::class, 'reopen' ), 'permission_callback' => $perm, 'args' => $rev ) );
		register_rest_route( self::NS, $id . '/rerun', array( 'methods' => 'POST', 'callback' => array( self::class, 'rerun' ), 'permission_callback' => $perm, 'args' => $rev ) );
		register_rest_route( self::NS, $id . '/retry', array( 'methods' => 'POST', 'callback' => array( self::class, 'retry' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, $id . '/plain', array( 'methods' => 'POST', 'callback' => array( self::class, 'plain' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, $id . '/send', array( 'methods' => 'POST', 'callback' => array( self::class, 'send' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, $id . '/check', array( 'methods' => 'POST', 'callback' => array( self::class, 'check' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, $id . '/images', array( 'methods' => 'POST', 'callback' => array( self::class, 'add_image' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, $id . '/images/(?P<asset>\d+)', array( 'methods' => 'DELETE', 'callback' => array( self::class, 'delete_image' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, '/jobs/status', array( 'methods' => 'GET', 'callback' => array( self::class, 'statuses' ), 'permission_callback' => $perm, 'args' => array( 'ids' => array( 'type' => 'string', 'required' => true ) ) ) );
	}

	public static function permission(): bool {
		return current_user_can( Capabilities::REVIEW );
	}

	/** @return object|\WP_Error */
	private static function job( \WP_REST_Request $r ) {
		$job = Jobs::get( (int) $r['id'] );
		return $job ?: new \WP_Error( 'cpub_not_found', 'This post doesn\'t exist (it may have been deleted).', array( 'status' => 404 ) );
	}

	/** The job's current view, or the error. */
	private static function respond( $result, int $id ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$job = Jobs::get( $id );
		return $job ? rest_ensure_response( Review::view( $job ) ) : rest_ensure_response( array( 'deleted' => true ) );
	}

	public static function get( \WP_REST_Request $r ) {
		$job = self::job( $r );
		return is_wp_error( $job ) ? $job : rest_ensure_response( Review::view( $job ) );
	}

	public static function preview( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$md = (string) $r['markdown'];
		if ( strlen( $md ) > Review::MAX_MARKDOWN ) {
			return new \WP_Error( 'cpub_too_long', 'The post is too long.', array( 'status' => 413 ) );
		}
		return rest_ensure_response( Review::preview( $job, $md ) );
	}

	public static function save( \WP_REST_Request $r ) {
		$job = self::job( $r );
		return is_wp_error( $job ) ? $job : self::respond( Review::save( $job, (string) $r['markdown'], (int) $r['revision'], self::options( $r ) ), $job->id );
	}

	public static function approve( \WP_REST_Request $r ) {
		$job = self::job( $r );
		return is_wp_error( $job ) ? $job : self::respond( Review::approve( $job, (string) $r['markdown'], (int) $r['revision'], self::options( $r ) ), $job->id );
	}

	/** Tags and publish date, when the editor sent them. */
	private static function options( \WP_REST_Request $r ): array {
		$out = array();
		if ( $r->has_param( 'tags' ) ) {
			$out['tags'] = (array) $r['tags'];
		}
		if ( $r->has_param( 'publish_at' ) ) {
			$out['publish_at'] = (string) $r['publish_at'];
		}
		return $out;
	}

	/** Take a post the agency published off the client site (it becomes a draft there). */
	public static function unpublish( \WP_REST_Request $r ) {
		$job = self::job( $r );
		return is_wp_error( $job ) ? $job : self::respond( \CPub\Publisher\Jobs\Sender::unpublish( $job ), $job->id );
	}

	/** The client site's tags matching a search, for the editor's tag picker. */
	public static function site_tags( \WP_REST_Request $r ) {
		$site = \CPub\Publisher\Connections\Sites::get( (int) $r['site'] );
		if ( ! $site || \CPub\Publisher\Connections\Sites::CONNECTED !== $site->status ) {
			return new \WP_Error( 'cpub_site', 'The client site is not connected.', array( 'status' => 404 ) );
		}
		$query = array( 'per_page' => 20, '_fields' => 'id,name', 'orderby' => 'count', 'order' => 'desc' );
		if ( '' !== trim( (string) $r['search'] ) ) {
			$query['search'] = mb_substr( trim( (string) $r['search'] ), 0, 100 );
		}
		$res = \CPub\Publisher\Connections\ConnectorClient::request( $site, 'GET', '/wp/v2/tags', array( 'query' => $query ) );
		if ( is_wp_error( $res ) || 200 !== $res['status'] || ! is_array( $res['data'] ) ) {
			return new \WP_Error( 'cpub_tags', 'Couldn\'t read the client site\'s tags: ' . \CPub\Publisher\Connections\Http::describe( $res ) . '.', array( 'status' => 502 ) );
		}
		$names = array();
		foreach ( $res['data'] as $t ) {
			if ( is_array( $t ) && isset( $t['name'] ) ) {
				$names[] = html_entity_decode( (string) $t['name'], ENT_QUOTES, 'UTF-8' );
			}
		}
		return rest_ensure_response( array( 'tags' => $names, 'can_create' => \CPub\Publisher\Jobs\Sender::can_create_tags( $site ) ) );
	}

	public static function reject( \WP_REST_Request $r ) {
		$job = self::job( $r );
		return is_wp_error( $job ) ? $job : self::respond( Review::reject( $job, (string) $r['reason'], (int) $r['revision'] ), $job->id );
	}

	public static function reopen( \WP_REST_Request $r ) {
		$job = self::job( $r );
		return is_wp_error( $job ) ? $job : self::respond( Review::reopen( $job, (int) $r['revision'] ), $job->id );
	}

	public static function rerun( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$ok = 'md' !== $job->source_format && Processor::rerun( $job, (int) $r['revision'] );
		return self::respond( $ok ? true : new \WP_Error( 'cpub_conflict', 'This post can\'t be sent back to the AI now (it changed, or it is a Markdown file). Reload the page.', array( 'status' => 409 ) ), $job->id );
	}

	public static function retry( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return self::respond( Processor::retry( $job ) ? true : new \WP_Error( 'cpub_conflict', 'Only a failed post can be retried.', array( 'status' => 409 ) ), $job->id );
	}

	public static function plain( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return self::respond( Processor::plain( $job ) ? true : new \WP_Error( 'cpub_plain', 'The post couldn\'t be set out as plain paragraphs. ' . (string) ( Jobs::get( $job->id )->last_error ?? '' ), array( 'status' => 409 ) ), $job->id );
	}

	/** Try a failed send again. */
	public static function send( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		return self::respond( \CPub\Publisher\Jobs\Sender::retry( $job ) ? true : new \WP_Error( 'cpub_conflict', 'Only a post whose sending failed can be sent again.', array( 'status' => 409 ) ), $job->id );
	}

	/** Look now at the draft on the client site (normally checked daily). */
	public static function check( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$found = \CPub\Publisher\Jobs\Sender::check( $job );
		return 'error' === $found ? new \WP_Error( 'cpub_check', 'Couldn\'t read the draft\'s state from the client site. Try again later.', array( 'status' => 502 ) ) : self::respond( true, $job->id );
	}

	public static function delete( \WP_REST_Request $r ) {
		$job = self::job( $r );
		return is_wp_error( $job ) ? $job : self::respond( Review::delete( $job ), $job->id );
	}

	public static function add_image( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$file = $r->get_file_params()['file'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$big = is_array( $file ) && in_array( (int) ( $file['error'] ?? 0 ), array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return new \WP_Error( 'cpub_upload', $big ? 'The image is larger than this server accepts (' . size_format( wp_max_upload_size() ) . ').' : 'The upload failed.', array( 'status' => 400 ) );
		}
		return self::add_image_bytes( $job, (string) $file['name'], (string) file_get_contents( (string) $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Split out so tests can add images without a real HTTP upload. */
	public static function add_image_bytes( object $job, string $name, string $bytes ) {
		$added = Review::add_image( $job, $name, $bytes );
		return self::respond( is_wp_error( $added ) ? $added : true, $job->id );
	}

	public static function delete_image( \WP_REST_Request $r ) {
		$job = self::job( $r );
		if ( is_wp_error( $job ) ) {
			return $job;
		}
		$asset = Assets::get( (int) $r['asset'] );
		if ( ! $asset || (int) $asset->job_id !== $job->id || Assets::IMAGE !== $asset->kind ) {
			return new \WP_Error( 'cpub_not_found', 'That image isn\'t part of this post.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $job->status, Review::IMAGE_STATES, true ) ) {
			return new \WP_Error( 'cpub_not_editable', 'Images can\'t be changed in this state.', array( 'status' => 409 ) );
		}
		Review::delete_image( $job, $asset );
		return self::respond( true, $job->id );
	}

	/** Status of several jobs, for the queue page to update itself. */
	public static function statuses( \WP_REST_Request $r ) {
		$out = array();
		foreach ( array_slice( array_filter( array_map( 'intval', explode( ',', (string) $r['ids'] ) ) ), 0, 200 ) as $id ) {
			$job        = Jobs::get( $id );
			$out[ $id ] = $job ? array( 'status' => $job->status, 'label' => Jobs::label( $job ), 'title' => $job->title ) : null;
		}
		return rest_ensure_response( $out );
	}
}
