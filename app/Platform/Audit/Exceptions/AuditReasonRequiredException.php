<?php

declare(strict_types=1);

namespace App\Platform\Audit\Exceptions;

use RuntimeException;

/**
 * AC3: thrown by `Audit::record()` when an action on
 * `SensitiveActions::ACTIONS` is recorded without a non-empty
 * `reason`.
 */
final class AuditReasonRequiredException extends RuntimeException
{
    public static function forAction(string $action): self
    {
        return new self(
            "Audit action [{$action}] is on the sensitive-action list (SensitiveActions::ACTIONS) ".
            'and requires a non-empty reason.'
        );
    }
}
