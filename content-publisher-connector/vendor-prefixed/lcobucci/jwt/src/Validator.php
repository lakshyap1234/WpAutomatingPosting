<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT;

use CPub\Connector\Vendor\Lcobucci\JWT\Validation\Constraint;
use CPub\Connector\Vendor\Lcobucci\JWT\Validation\NoConstraintsGiven;
use CPub\Connector\Vendor\Lcobucci\JWT\Validation\RequiredConstraintsViolated;

interface Validator
{
    /**
     * @throws RequiredConstraintsViolated
     * @throws NoConstraintsGiven
     */
    public function assert(Token $token, Constraint ...$constraints): void;

    /** @throws NoConstraintsGiven */
    public function validate(Token $token, Constraint ...$constraints): bool;
}
