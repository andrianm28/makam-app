<?php

declare(strict_types=1);

namespace App\Platform\Audit\Exceptions;

use RuntimeException;

/**
 * The authenticated actor may not read the audit trail at all — or holds no
 * audit-review authority. See `Contracts\AuditReadAuthorizer` for the policy
 * this guards.
 *
 * Fail-closed: no role grant means no authorisation, never "unrestricted
 * until someone configures it", and an empty role list is not permission.
 *
 * Carries no actor identity detail, no action name, and no subject
 * reference — a refusal must not itself leak what the audit trail contains.
 */
final class AuditReadNotAuthorisedException extends RuntimeException
{
    public static function forActorContext(): self
    {
        return new self(
            'The authenticated actor has no explicit authority to read the audit trail.'
        );
    }
}
