<?php

declare(strict_types=1);

namespace App\Platform\Audit\Exceptions;

use RuntimeException;

/**
 * AC1: thrown by `App\Platform\Audit\Models\AuditEvent`'s
 * `update()`/`performUpdate()`/`delete()` overrides — the
 * application-level half of append-only enforcement. See that model's
 * class-level doc block for exactly what this does and does not
 * guarantee (it is NOT the database-level enforcement design.md
 * names; see that doc block and finding N-1).
 */
final class AuditRecordIsImmutableException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "audit_events rows are append-only; {$operation}() is not permitted on ".
            'App\Platform\Audit\Models\AuditEvent.'
        );
    }
}
