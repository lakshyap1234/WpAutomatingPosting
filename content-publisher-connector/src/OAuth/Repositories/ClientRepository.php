<?php
/**
 * The only client is the trusted agency from config/agency.php.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Repositories;

use CPub\Connector\OAuth\Agency;
use CPub\Connector\OAuth\Entities\ClientEntity;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\ClientEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Repositories\ClientRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClientRepository implements ClientRepositoryInterface {

	public function getClientEntity( string $clientIdentifier ): ?ClientEntityInterface {
		$agency = Agency::config();
		if ( '' === $agency['client_id'] || ! hash_equals( $agency['client_id'], $clientIdentifier ) || ! $agency['redirect_uris'] ) {
			return null;
		}
		return new ClientEntity( $agency['client_id'], $agency['name'], $agency['redirect_uris'] );
	}

	/** Only called for confidential clients; ours is public. */
	public function validateClient( string $clientIdentifier, ?string $clientSecret, ?string $grantType ): bool {
		return false;
	}
}
