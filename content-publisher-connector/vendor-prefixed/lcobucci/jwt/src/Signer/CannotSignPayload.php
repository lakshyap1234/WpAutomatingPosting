<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT\Signer;

use InvalidArgumentException;
use CPub\Connector\Vendor\Lcobucci\JWT\Exception;

final class CannotSignPayload extends InvalidArgumentException implements Exception
{
    public static function errorHappened(string $error): self
    {
        return new self('There was an error while creating the signature:' . $error);
    }
}
