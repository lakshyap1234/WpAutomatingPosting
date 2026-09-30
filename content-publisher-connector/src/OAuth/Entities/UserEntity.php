<?php
/**
 * The WordPress user tokens act as (the Agency Publisher user).
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Entities;

use CPub\Connector\Vendor\League\OAuth2\Server\Entities\UserEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class UserEntity implements UserEntityInterface {
	use EntityTrait;

	public function __construct( int $user_id ) {
		$this->setIdentifier( (string) $user_id );
	}
}
