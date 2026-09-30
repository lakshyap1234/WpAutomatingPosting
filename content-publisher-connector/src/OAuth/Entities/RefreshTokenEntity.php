<?php
/**
 * Refresh token (single use: each use returns a new one).
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Entities;

use CPub\Connector\Vendor\League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RefreshTokenEntity implements RefreshTokenEntityInterface {
	use EntityTrait;
	use RefreshTokenTrait;
}
