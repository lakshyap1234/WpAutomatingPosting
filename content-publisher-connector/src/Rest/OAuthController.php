<?php
/**
 * OAuth endpoints: discovery, token and revocation.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\Rest;

use CPub\Connector\ActivityLog;
use CPub\Connector\Admin\AuthorizePage;
use CPub\Connector\Auth\BearerAuth;
use CPub\Connector\OAuth\Agency;
use CPub\Connector\OAuth\GrantContext;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\Psr;
use CPub\Connector\OAuth\SealedToken;
use CPub\Connector\OAuth\Scopes;
use CPub\Connector\OAuth\Server;
use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Plugin;
use CPub\Connector\PublisherUser;
use CPub\Connector\Vendor\League\OAuth2\Server\Exception\OAuthServerException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OAuthController {

	public function register_routes(): void {
		$ns = Plugin::REST_NAMESPACE;
		register_rest_route( $ns, '/oauth/metadata', array( 'methods' => 'GET', 'callback' => array( $this, 'metadata' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/oauth/token', array( 'methods' => 'POST', 'callback' => array( $this, 'token' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/oauth/revoke', array( 'methods' => 'POST', 'callback' => array( $this, 'revoke' ), 'permission_callback' => '__return_true' ) );
		register_rest_route(
			$ns,
			'/connection',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'connection' ),
				'permission_callback' => fn() => null !== BearerAuth::grant(),
			)
		);
	}

	/** Authorization server metadata, in the shape of RFC 8414. */
	public function metadata(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'issuer'                                => home_url( '/' ),
				'authorization_endpoint'                => AuthorizePage::url(),
				'token_endpoint'                        => rest_url( Plugin::REST_NAMESPACE . '/oauth/token' ),
				'revocation_endpoint'                   => rest_url( Plugin::REST_NAMESPACE . '/oauth/revoke' ),
				'scopes_supported'                      => Scopes::all(),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'authorization_response_iss_parameter_supported' => true,
				'token_endpoint_auth_methods_supported' => array( 'none' ),
				'connector_version'                     => CPUB_CONNECTOR_VERSION,
				'site_name'                             => get_bloginfo( 'name' ),
			)
		);
	}

	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		GrantContext::clear();
		$params = $request->get_body_params();
		$type   = (string) ( $params['grant_type'] ?? '' );
		$psr    = Psr::request( 'POST', rest_url( Plugin::REST_NAMESPACE . '/oauth/token' ), array(), $params );

		try {
			// PKCE S256 is checked here too, not only on the consent screen.
			if ( 'authorization_code' === $type ) {
				$code = ( new SealedToken() )->payload( (string) ( $params['code'] ?? '' ) );
				if ( $code && 'S256' !== ( $code['code_challenge_method'] ?? null ) ) {
					throw OAuthServerException::invalidGrant( 'The authorization code was not issued with PKCE S256.' );
				}
			}
			$response = Server::authorization_server()->respondToAccessTokenRequest( $psr, Psr::response() );
			// Routine hourly renewals aren't logged: they'd bury what the site owner cares about.
			if ( GrantContext::has() && 'authorization_code' === $type ) {
				Grants::activate( GrantContext::get() );
			}
		} catch ( OAuthServerException $e ) {
			$response = $e->generateHttpResponse( Psr::response() );
			if ( GrantContext::has() ) {
				ActivityLog::event( GrantContext::get(), 'token_refused', $e->getHint() ?: $e->getMessage(), 0, $e->getHttpStatusCode() );
			}
		} catch ( \Throwable $e ) {
			error_log( 'Content Publisher Connector token error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$response = OAuthServerException::serverError( 'The site could not issue a token.' )->generateHttpResponse( Psr::response() );
		} finally {
			GrantContext::clear();
		}
		return Psr::to_rest( $response );
	}

	/**
	 * Token revocation (RFC 7009). Revoking either token ends the whole
	 * connection: this is the agency's "Disconnect".
	 */
	public function revoke( \WP_REST_Request $request ): \WP_REST_Response {
		$client_id = (string) $request->get_param( 'client_id' );
		if ( ! hash_equals( Agency::client_id(), $client_id ) ) {
			return Psr::to_rest( OAuthServerException::invalidClient( Psr::request( 'POST', rest_url() ) )->generateHttpResponse( Psr::response() ) );
		}

		// Only a token that still works can end the connection, so an old one
		// turning up in a log somewhere can't be used to disconnect the site.
		$token = (string) $request->get_param( 'token' );
		$row   = null;
		if ( str_starts_with( $token, TokenStore::ACCESS_PREFIX ) ) {
			$row = TokenStore::find( TokenStore::ACCESS, substr( $token, strlen( TokenStore::ACCESS_PREFIX ) ) );
		} else {
			$payload = ( new SealedToken() )->payload( $token );
			$row     = isset( $payload['refresh_token_id'] ) ? TokenStore::find( TokenStore::REFRESH, (string) $payload['refresh_token_id'] ) : null;
			if ( $row && null !== $row->used_at ) {
				$row = null;
			}
		}
		if ( $row && null === $row->revoked_at && strtotime( $row->expires_at . ' UTC' ) > time() ) {
			Grants::revoke( (int) $row->grant_id, 'revoked_by_agency', 0 );
		}
		// Always 200, whether or not the token was known (RFC 7009 section 2.2).
		return Psr::to_rest( Psr::response()->withStatus( 200 ) );
	}

	/** Connection details for the agency's health check (scope account:read). */
	public function connection(): \WP_REST_Response {
		$grant = BearerAuth::grant();
		$user  = get_userdata( (int) $grant->user_id );
		return new \WP_REST_Response(
			array(
				'site_name'         => get_bloginfo( 'name' ),
				'site_url'          => home_url( '/' ),
				'connector_version' => CPUB_CONNECTOR_VERSION,
				'scopes'            => Grants::scopes( $grant ),
				'connected_since'   => mysql_to_rfc3339( $grant->activated_at ),
				'user'              => $user ? array( 'id' => $user->ID, 'name' => $user->display_name, 'role' => (string) ( array_values( (array) $user->roles )[0] ?? '' ), 'separate_account' => PublisherUser::is_dedicated( $user->ID ) ) : null,
				'can_publish'       => Grants::can_publish( $grant ),
			)
		);
	}
}
