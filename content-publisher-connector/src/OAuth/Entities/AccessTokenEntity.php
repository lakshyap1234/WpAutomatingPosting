<?php
/**
 * Access token. Opaque: a prefixed random string checked against the database on every request, rather than a signed JWT, so revoking it takes effect immediately.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Entities;

use CPub\Connector\OAuth\TokenStore;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AccessTokenEntity implements AccessTokenEntityInterface {
	use AccessTokenTrait;
	use EntityTrait;
	use TokenEntityTrait;

	public function toString(): string {
		return TokenStore::access_string( $this->getIdentifier() );
	}
}
