<?php
/**
 * Access tokens, stored as hashes and tied to the connection in GrantContext.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Repositories;

use CPub\Connector\OAuth\Entities\AccessTokenEntity;
use CPub\Connector\OAuth\GrantContext;
use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Exception\OAuthServerException;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\ClientEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessTokenRepository implements AccessTokenRepositoryInterface {

	public function getNewToken( ClientEntityInterface $clientEntity, array $scopes, string|null $userIdentifier = null ): AccessTokenEntityInterface {
		$token = new AccessTokenEntity();
		$token->setClient( $clientEntity );
		foreach ( $scopes as $scope ) {
			$token->addScope( $scope );
		}
		if ( null !== $userIdentifier ) {
			$token->setUserIdentifier( $userIdentifier );
		}
		return $token;
	}

	public function persistNewAccessToken( AccessTokenEntityInterface $accessTokenEntity ): void {
		// Code exchange: every check has passed, so use the code up now. If a
		// parallel request got there first, it was used twice: refuse and cut off.
		$code = GrantContext::take_pending_code();
		if ( null !== $code && ! TokenStore::claim( TokenStore::CODE, $code ) ) {
			AuthCodeRepository::used_twice( GrantContext::get() );
			throw OAuthServerException::invalidGrant( 'Authorization code has already been used' );
		}
		Persist::insert( TokenStore::ACCESS, $accessTokenEntity->getIdentifier(), GrantContext::get(), $accessTokenEntity->getExpiryDateTime() );
	}

	public function revokeAccessToken( string $tokenId ): void {
		TokenStore::revoke( TokenStore::ACCESS, $tokenId );
	}

	public function isAccessTokenRevoked( string $tokenId ): bool {
		$row = TokenStore::find( TokenStore::ACCESS, $tokenId );
		return ! $row || null !== $row->revoked_at;
	}
}
