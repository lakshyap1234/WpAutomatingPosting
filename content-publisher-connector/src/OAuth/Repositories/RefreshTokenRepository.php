<?php
/**
 * Refresh tokens: single use (rotation). Presenting one that was already used means it was copied, so the whole connection is cut off (OAuth 2.0 Security BCP, RFC 9700 section 4.14).
 *
 * One exception (a "reuse interval", as common OAuth servers have): if the
 * same refresh token comes back within TokenStore::REISSUE_GRACE seconds and
 * the replacement we issued for it was never used, the agency most likely
 * lost our reply (timeout, crash). We then issue a new pair and retire the
 * unused replacement, instead of cutting the connection. A thief using a copy
 * inside that window still gets caught: the agency's next renewal presents a
 * retired token, which is reuse, and the whole connection is cut.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Repositories;

use CPub\Connector\OAuth\Entities\RefreshTokenEntity;
use CPub\Connector\OAuth\GrantContext;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RefreshTokenRepository implements RefreshTokenRepositoryInterface {

	private static function reissue_allowed( object $row ): bool {
		if ( null === $row->used_at || strtotime( $row->used_at . ' UTC' ) < time() - TokenStore::REISSUE_GRACE ) {
			return false;
		}
		$children = TokenStore::children( (int) $row->id );
		$refresh  = array_values( array_filter( $children, fn( $t ) => TokenStore::REFRESH === $t->kind ) );
		// Exactly one replacement, never used: retire it atomically (so two retries can't both win).
		if ( 1 !== count( $refresh ) || ! TokenStore::retire_unused( (int) $refresh[0]->id ) ) {
			return false;
		}
		foreach ( $children as $child ) {
			TokenStore::revoke_id( (int) $child->id );
		}
		return true;
	}

	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

	public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
		Persist::insert( TokenStore::REFRESH, $refreshTokenEntity->getIdentifier(), GrantContext::get(), $refreshTokenEntity->getExpiryDateTime() );
	}

	public function revokeRefreshToken( string $tokenId ): void {
		TokenStore::revoke( TokenStore::REFRESH, $tokenId );
	}

	public function isRefreshTokenRevoked( string $tokenId ): bool {
		$row = TokenStore::find( TokenStore::REFRESH, $tokenId );
		if ( ! $row ) {
			return true;
		}
		GrantContext::set( (int) $row->grant_id );
		$grant = Grants::get( (int) $row->grant_id );
		if ( ! $grant || Grants::ACTIVE !== $grant->status ) {
			return true;
		}
		if ( TokenStore::claim( TokenStore::REFRESH, $tokenId ) ) {
			GrantContext::set_parent( (int) $row->id );
			return false;
		}
		if ( self::reissue_allowed( $row ) ) {
			GrantContext::set_parent( (int) $row->id );
			\CPub\Connector\ActivityLog::event( (int) $row->grant_id, 'token_reissued', 'The agency repeated a token renewal (its previous reply was probably lost). The unused replacement was retired.' );
			return false;
		}
		if ( null !== $row->used_at || null !== $row->revoked_at ) {
			Grants::revoke( (int) $row->grant_id, 'refresh_token_reuse', 0 );
		}
		return true;
	}
}
