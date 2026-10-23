<?php
/**
 * The agency's side of proposed changes (proposals:write):
 *
 *   GET  /cpub-connector/v1/proposals/source/{post}  the text of a published post or page to start from
 *   POST /cpub-connector/v1/proposals                suggest a new title, content or excerpt for it
 *   GET  /cpub-connector/v1/proposals[?post=&state=] suggestions and their state, newest first
 *   GET  /cpub-connector/v1/proposals/{id}           one suggestion
 *
 * Nothing here writes to a post: see Proposals.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Rest;

use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\Plugin;
use CPub\Connector\Proposals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ProposalsController {

	private const STATES = array( Proposals::PENDING, Proposals::APPLIED, Proposals::REJECTED, Proposals::SUPERSEDED );

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$ns    = Plugin::REST_NAMESPACE;
		$token = fn() => null !== BearerAuth::grant();
		register_rest_route(
			$ns,
			'/proposals',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'create' ),
					'permission_callback' => $token,
					'args'                => array(
						'post'    => array( 'type' => 'integer', 'required' => true ),
						'title'   => array( 'type' => 'string' ),
						'content' => array( 'type' => 'string' ),
						'excerpt' => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'list' ),
					'permission_callback' => $token,
					'args'                => array(
						'post'  => array( 'type' => 'integer' ),
						'state' => array( 'type' => 'string', 'enum' => self::STATES ),
					),
				),
			)
		);
		register_rest_route( $ns, '/proposals/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( self::class, 'show' ), 'permission_callback' => $token ) );
		register_rest_route( $ns, '/proposals/source/(?P<post>\d+)', array( 'methods' => 'GET', 'callback' => array( self::class, 'source' ), 'permission_callback' => $token ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function create( \WP_REST_Request $request ) {
		$post = Proposals::target( (int) $request['post'] );
		if ( ! $post ) {
			return self::not_a_target();
		}
		$changes = Proposals::clean( array_intersect_key( (array) $request->get_params(), array_flip( Proposals::FIELDS ) ) );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}
		$id = Proposals::create( (int) BearerAuth::grant()->id, $post, $changes );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new \WP_REST_Response( self::shape( Proposals::get( $id ), true ), 201 );
	}

	public static function list( \WP_REST_Request $request ): \WP_REST_Response {
		$state = (string) $request['state'];
		$rows  = Proposals::find( '' !== $state ? array( $state ) : null, (int) $request['post'] );
		return new \WP_REST_Response( array_map( fn( $p ) => self::shape( $p, false ), $rows ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public static function show( \WP_REST_Request $request ) {
		$proposal = Proposals::get( (int) $request['id'] );
		if ( ! $proposal ) {
			return new \WP_Error( 'cpub_proposal_not_found', 'There is no suggestion with this ID.', array( 'status' => 404 ) );
		}
		return new \WP_REST_Response( self::shape( $proposal, true ) );
	}

	/** The post's own text (block markup), to base a suggestion on. @return \WP_REST_Response|\WP_Error */
	public static function source( \WP_REST_Request $request ) {
		$post = Proposals::target( (int) $request['post'] );
		if ( ! $post ) {
			return self::not_a_target();
		}
		return new \WP_REST_Response(
			array(
				'id'           => $post->ID,
				'type'         => $post->post_type,
				'link'         => get_permalink( $post ),
				'title'        => $post->post_title,
				'content'      => $post->post_content,
				'excerpt'      => $post->post_excerpt,
				'modified_gmt' => mysql_to_rfc3339( $post->post_modified_gmt ),
			)
		);
	}

	private static function not_a_target(): \WP_Error {
		// The same answer whether the post is a draft, private or missing.
		return new \WP_Error( 'cpub_proposal_target', 'Suggestions are only for this site\'s published posts and pages (not drafts, private or password-protected ones).', array( 'status' => 404 ) );
	}

	private static function shape( object $proposal, bool $full ): array {
		$post = get_post( (int) $proposal->post_id );
		$out  = array(
			'id'         => (int) $proposal->id,
			'post'       => (int) $proposal->post_id,
			'post_type'  => $post ? $post->post_type : null,
			'state'      => $proposal->state,
			'fields'     => Proposals::changed( $proposal ),
			'created_at' => mysql_to_rfc3339( $proposal->created_at ),
			'decided_at' => $proposal->decided_at ? mysql_to_rfc3339( $proposal->decided_at ) : null,
			// The editor's reason for rejecting it, if they gave one.
			'note'       => '' !== (string) $proposal->note ? (string) $proposal->note : null,
		);
		if ( $full ) {
			foreach ( Proposals::FIELDS as $field ) {
				$out['proposed'][ $field ] = $proposal->$field;
			}
		}
		return $out;
	}
}
