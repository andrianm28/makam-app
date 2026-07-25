<?php

declare(strict_types=1);

namespace App\Platform\Audit\Exceptions;

use RuntimeException;

/**
 * AC5: thrown by `MetadataAllowlist::assertAllowed()` when a caller
 * attempts to write a metadata key outside
 * `App\Platform\Audit\MetadataAllowlist::ALLOWED_KEYS`.
 */
final class AuditMetadataKeyNotAllowedException extends RuntimeException
{
    /**
     * @param  list<string>  $keys
     */
    public static function forKeys(array $keys): self
    {
        $list = implode(', ', $keys);

        return new self(
            "Audit metadata key(s) not on the allowlist: [{$list}]. ".
            'See App\Platform\Audit\MetadataAllowlist::ALLOWED_KEYS.'
        );
    }
}
