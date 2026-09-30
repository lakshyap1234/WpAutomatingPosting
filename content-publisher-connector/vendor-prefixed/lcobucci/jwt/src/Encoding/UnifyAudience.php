<?php
declare(strict_types=1);

namespace CPub\Connector\Vendor\Lcobucci\JWT\Encoding;

use CPub\Connector\Vendor\Lcobucci\JWT\ClaimsFormatter;
use CPub\Connector\Vendor\Lcobucci\JWT\Token\RegisteredClaims;

use function array_key_exists;
use function count;
use function current;

final class UnifyAudience implements ClaimsFormatter
{
    /** @inheritdoc */
    public function formatClaims(array $claims): array
    {
        if (
            ! array_key_exists(RegisteredClaims::AUDIENCE, $claims)
            || count($claims[RegisteredClaims::AUDIENCE]) !== 1
        ) {
            return $claims;
        }

        $claims[RegisteredClaims::AUDIENCE] = current($claims[RegisteredClaims::AUDIENCE]);

        return $claims;
    }
}
