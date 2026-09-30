<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT\Validation;

use CPub\Connector\Vendor\Lcobucci\JWT\Token;

interface Constraint
{
    /** @throws ConstraintViolation */
    public function assert(Token $token): void;
}
