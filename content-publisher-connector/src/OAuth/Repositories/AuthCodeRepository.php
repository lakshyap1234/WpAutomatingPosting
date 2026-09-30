<?php
/**
 * Authorization codes: single use. A second use of the same code cuts off the connection it created (RFC 6749 section 4.1.2).
 *
 * The code is only marked used once the whole request has passed, including
 * the PKCE check (see AccessTokenRepository::persistNewAccessToken). Otherwise
 * someone who intercepted a code, but lacks the verifier, could burn it.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Repositories;

use CPub\Connector\OAuth\Entities\AuthCodeEntity;
use CPub\Connector\OAuth\GrantContext;
use CPub\Connector\OAuth\Grants;
use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthCodeRepository implements AuthCodeRepositoryInterface {

	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCodeEntity();
	}

	public function persistNewAuthCode( AuthCodeEntityInterface $authCodeEntity ): void {
		Persist::insert( TokenStore::CODE, $authCodeEntity->getIdentifier(), GrantContext::get(), $authCodeEntity->getExpiryDateTime() );
	}

	public function revokeAuthCode( string $codeId ): void {
		TokenStore::revoke( TokenStore::CODE, $codeId );
	}

	/** Called while exchanging a code, before the PKCE check. Does not use the code up. */
	public function isAuthCodeRevoked( string $codeId ): bool {
		$row = TokenStore::find( TokenStore::CODE, $codeId );
		if ( ! $row ) {
			return true;
		}
		GrantContext::set( (int) $row->grant_id );
		if ( null !== $row->used_at ) {
			self::used_twice( (int) $row->grant_id );
			return true;
		}
		if ( null !== $row->revoked_at || strtotime( $row->expires_at . ' UTC' ) <= time() ) {
			return true;
		}
		GrantContext::set_pending_code( $codeId );
		return false;
	}

	public static function used_twice( int $grant_id ): void {
		Grants::revoke( $grant_id, 'code_reuse', 0 );
	}
}
