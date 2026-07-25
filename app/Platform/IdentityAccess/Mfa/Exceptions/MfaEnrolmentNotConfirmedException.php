<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Exceptions;

use RuntimeException;

/**
 * Thrown by `MfaChallengeService`/`MfaRecoveryService` when asked to verify
 * against an enrolment that is not `MfaEnrolmentStatus::CONFIRMED` (e.g.
 * still pending, or revoked). A caller reaching this means it is trying to
 * challenge an actor who has no active MFA enrolment at all — a caller/
 * wiring bug, since the (not-yet-built) future login flow is expected to
 * only present a challenge when `ActorContext::$mfaState` already reports
 * an enrolled state.
 */
final class MfaEnrolmentNotConfirmedException extends RuntimeException
{
    public static function forEnrolment(int $enrolmentId, string $actualStatus): self
    {
        return new self(
            "MFA enrolment [{$enrolmentId}] is not confirmed (status: [{$actualStatus}]) and cannot be challenged."
        );
    }
}
