<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Exceptions;

use RuntimeException;

/**
 * Thrown by `DocumentAccessEvent` when application code attempts to revise or
 * delete an access event after insertion. Database-level UPDATE/DELETE
 * revocation is a separate Task 8 deployment concern.
 */
final class DocumentAccessEventIsImmutableException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "document_access_events rows are append-only; {$operation}() is not permitted on ".
            'App\Platform\DocumentVault\Models\DocumentAccessEvent.'
        );
    }
}
