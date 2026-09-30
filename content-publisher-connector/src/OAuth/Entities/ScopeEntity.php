<?php
/**
 * A permission (see Scopes).
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Entities;

use CPub\Connector\Vendor\League\OAuth2\Server\Entities\ScopeEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\ScopeTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScopeEntity implements ScopeEntityInterface {
	use EntityTrait;
	use ScopeTrait;

	public function __construct( string $identifier ) {
		$this->setIdentifier( $identifier );
	}
}
