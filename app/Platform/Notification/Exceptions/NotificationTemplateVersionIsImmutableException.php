<?php

declare(strict_types=1);

namespace App\Platform\Notification\Exceptions;

use RuntimeException;

/**
 * A template version is a historical render snapshot and cannot be changed
 * after insertion. Create a new version instead.
 */
final class NotificationTemplateVersionIsImmutableException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self("Notification template version is immutable; {$operation} is not allowed.");
    }
}
