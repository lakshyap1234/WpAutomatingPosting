<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT\Validation\Constraint;

use CPub\Connector\Vendor\Lcobucci\JWT\Token;
use CPub\Connector\Vendor\Lcobucci\JWT\Validation\Constraint;
use CPub\Connector\Vendor\Lcobucci\JWT\Validation\ConstraintViolation;

final class PermittedFor implements Constraint
{
    /** @param non-empty-string $audience */
    public function __construct(private readonly string $audience)
    {
    }

    public function assert(Token $token): void
    {
        if (! $token->isPermittedFor($this->audience)) {
            throw ConstraintViolation::error(
                'The token is not allowed to be used by this audience',
                $this,
            );
        }
    }
}
