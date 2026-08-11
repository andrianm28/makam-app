<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Exceptions;

use LogicException;

/**
 * A signed URL grant is durable issuance evidence. Only the single-use
 * `consumed_at` transition exposed by `SignedUrlGrant::consume()` may change
 * an issued row.
 */
final class SignedUrlGrantImmutableException extends LogicException
{
    public static function forOperation(string $operation): self
    {
        return new self("Signed URL grants are immutable; {$operation} is not allowed.");
    }
}
