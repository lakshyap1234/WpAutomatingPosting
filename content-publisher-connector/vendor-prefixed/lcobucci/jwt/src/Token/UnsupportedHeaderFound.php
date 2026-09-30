<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT\Token;

use InvalidArgumentException;
use CPub\Connector\Vendor\Lcobucci\JWT\Exception;

final class UnsupportedHeaderFound extends InvalidArgumentException implements Exception
{
    public static function encryption(): self
    {
        return new self('Encryption is not supported yet');
    }
}
