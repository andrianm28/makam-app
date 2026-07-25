<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Exceptions;

use RuntimeException;

/**
 * Thrown by `MfaEnrolmentService::confirm()` when the given enrolment is
 * not in `MfaEnrolmentStatus::PENDING` — e.g. already confirmed, or
 * revoked. Confirming twice, or confirming a revoked row, is a caller bug,
 * not a user-facing "wrong code" outcome, so this is a thrown exception
 * rather than a result value like `MfaChallengeResult`.
 */
final class MfaEnrolmentNotPendingException extends RuntimeException
{
    public static function forEnrolment(int $enrolmentId, string $actualStatus): self
    {
        return new self(
            "MFA enrolment [{$enrolmentId}] is not pending confirmation (status: [{$actualStatus}])."
        );
    }
}
