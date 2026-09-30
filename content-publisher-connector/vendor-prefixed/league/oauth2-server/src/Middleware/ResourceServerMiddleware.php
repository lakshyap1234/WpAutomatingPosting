<?php

/**
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */

declare(strict_types=1);

namespace CPub\Connector\Vendor\League\OAuth2\Server\Middleware;

use CPub\Connector\Vendor\League\OAuth2\Server\Exception\OAuthServerException;
use CPub\Connector\Vendor\League\OAuth2\Server\ResourceServer;
use CPub\Connector\Vendor\Psr\Http\Message\ResponseInterface;
use CPub\Connector\Vendor\Psr\Http\Message\ServerRequestInterface;

class ResourceServerMiddleware
{
    public function __construct(private ResourceServer $server)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, callable $next): ResponseInterface
    {
        try {
            $request = $this->server->validateAuthenticatedRequest($request);
        } catch (OAuthServerException $exception) {
            return $exception->generateHttpResponse($response);
        }

        // Pass the request and response on to the next responder in the chain
        return $next($request, $response);
    }
}
