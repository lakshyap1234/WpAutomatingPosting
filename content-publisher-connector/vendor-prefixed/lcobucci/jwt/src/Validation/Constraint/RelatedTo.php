<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT\Validation\Constraint;

use CPub\Connector\Vendor\Lcobucci\JWT\Token;
use CPub\Connector\Vendor\Lcobucci\JWT\Validation\Constraint;
use CPub\Connector\Vendor\Lcobucci\JWT\Validation\ConstraintViolation;

final class RelatedTo implements Constraint
{
    /** @param non-empty-string $subject */
    public function __construct(private readonly string $subject)
    {
    }

    public function assert(Token $token): void
    {
        if (! $token->isRelatedTo($this->subject)) {
            throw ConstraintViolation::error(
                'The token is not related to the expected subject',
                $this,
            );
        }
    }
}
