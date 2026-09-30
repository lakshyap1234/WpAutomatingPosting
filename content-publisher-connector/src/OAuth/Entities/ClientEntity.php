<?php
/**
 * The agency, as an OAuth client. Public (no secret): PKCE protects the code instead.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth\Entities;

use CPub\Connector\Vendor\League\OAuth2\Server\Entities\ClientEntityInterface;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\ClientTrait;
use CPub\Connector\Vendor\League\OAuth2\Server\Entities\Traits\EntityTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClientEntity implements ClientEntityInterface {
	use ClientTrait;
	use EntityTrait;

	/** @param string[] $redirect_uris */
	public function __construct( string $identifier, string $name, array $redirect_uris ) {
		$this->setIdentifier( $identifier );
		$this->name           = $name;
		$this->redirectUri    = $redirect_uris;
		$this->isConfidential = false;
	}
}
