<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT\Validation;

use CPub\Connector\Vendor\Lcobucci\JWT\Exception;
use RuntimeException;

final class NoConstraintsGiven extends RuntimeException implements Exception
{
}
