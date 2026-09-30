<?php
/**
 * Scopes known to the Connector; unknown ones are rejected.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Repositories;

use CPub\Connector\OAuth\Entities\ScopeEntity;
use CPub\Connector\OAuth\Scopes;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\ClientEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\ScopeEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScopeRepository implements ScopeRepositoryInterface {

	public function getScopeEntityByIdentifier( string $identifier ): ?ScopeEntityInterface {
		return Scopes::exists( $identifier ) ? new ScopeEntity( $identifier ) : null;
	}

	public function finalizeScopes( array $scopes, string $grantType, ClientEntityInterface $clientEntity, string|null $userIdentifier = null, ?string $authCodeId = null ): array {
		return array_values( array_filter( $scopes, fn( $s ) => Scopes::exists( $s->getIdentifier() ) ) );
	}
}
