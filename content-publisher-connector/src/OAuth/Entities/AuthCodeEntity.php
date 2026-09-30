<?php
/**
 * Authorization code (single use, 10 minutes).
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Entities;

use CPub\Connector\Vendor\League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthCodeEntity implements AuthCodeEntityInterface {
	use AuthCodeTrait;
	use EntityTrait;
	use TokenEntityTrait;
}
