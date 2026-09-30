<?php
/**
 * Builds the OAuth 2 authorization server (league/oauth2-server).
 *
 * Grants: authorization code with PKCE (S256 enforced by AuthorizePage) and
 * refresh token with rotation. No password, implicit or client-credentials grants.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

use CPub\Connector\OAuth\Repositories\AccessTokenRepository;
use CPub\Connector\OAuth\Repositories\AuthCodeRepository;
use CPub\Connector\OAuth\Repositories\ClientRepository;
use CPub\Connector\OAuth\Repositories\RefreshTokenRepository;
use CPub\Connector\OAuth\Repositories\ScopeRepository;
use CPub\Connector\Vendor\League\OAuth2\Server\AuthorizationServer;
use CPub\Connector\Vendor\League\OAuth2\Server\CryptKey;
use CPub\Connector\Vendor\League\OAuth2\Server\Grant\AuthCodeGrant;
use CPub\Connector\Vendor\League\OAuth2\Server\Grant\RefreshTokenGrant;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Server {

	public const ACCESS_TTL  = 'PT1H';
	public const REFRESH_TTL = 'P90D';
	public const CODE_TTL    = 'PT10M';

	public static function authorization_server(): AuthorizationServer {
		$server = new AuthorizationServer(
			new ClientRepository(),
			new AccessTokenRepository(),
			new ScopeRepository(),
			new CryptKey( Keys::signing_key(), null, false ),
			Keys::encryption_key()
		);

		$refresh_ttl = new \DateInterval( self::REFRESH_TTL );
		$access_ttl  = new \DateInterval( self::ACCESS_TTL );

		$auth_code = new AuthCodeGrant( new AuthCodeRepository(), new RefreshTokenRepository(), new \DateInterval( self::CODE_TTL ) );
		$auth_code->setRefreshTokenTTL( $refresh_ttl );
		// PKCE is enforced (S256 only) by AuthorizePage after the client and return
		// address are verified, so a missing challenge is reported back to the agency.
		$auth_code->disableRequireCodeChallengeForPublicClients();
		$server->enableGrantType( $auth_code, $access_ttl );

		$refresh = new RefreshTokenGrant( new RefreshTokenRepository() );
		$refresh->setRefreshTokenTTL( $refresh_ttl );
		$server->enableGrantType( $refresh, $access_ttl );

		return $server;
	}
}
