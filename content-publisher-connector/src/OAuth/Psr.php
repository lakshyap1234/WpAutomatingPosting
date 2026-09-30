<?php
/**
 * Converts between WordPress requests/responses and the PSR-7 objects the OAuth library uses.
 *
 * @package CPub\Connector
 */

namespace CPub\Connector\OAuth;

use CPub\Connector\Vendor\Nyholm\Psr7\Factory\Psr17Factory;
use CPub\Connector\Vendor\Nyholm\Psr7\Response;
use CPub\Connector\Vendor\Psr\Http\Message\ResponseInterface;
use CPub\Connector\Vendor\Psr\Http\Message\ServerRequestInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Psr {

	public static function request( string $method, string $uri, array $query = array(), array $body = array() ): ServerRequestInterface {
		$request = ( new Psr17Factory() )->createServerRequest( $method, $uri, array() )->withQueryParams( $query );
		if ( $body ) {
			$request = $request->withParsedBody( $body )->withHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		}
		return $request;
	}

	public static function response(): ResponseInterface {
		return new Response();
	}

	/** OAuth responses must not be cached (RFC 6749 section 5.1). */
	public static function to_rest( ResponseInterface $response ): \WP_REST_Response {
		$body = json_decode( (string) $response->getBody(), true );
		$rest = new \WP_REST_Response( is_array( $body ) ? $body : null, $response->getStatusCode() );
		foreach ( $response->getHeaders() as $name => $values ) {
			if ( in_array( strtolower( $name ), array( 'content-type', 'content-length' ), true ) ) {
				continue;
			}
			$rest->header( $name, implode( ', ', $values ) );
		}
		$rest->header( 'Cache-Control', 'no-store' );
		$rest->header( 'Pragma', 'no-cache' );
		return $rest;
	}
}
