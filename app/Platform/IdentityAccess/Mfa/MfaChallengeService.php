<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Mfa\Exceptions\MfaEnrolmentNotConfirmedException;
use App\Platform\IdentityAccess\Mfa\Models\MfaChallenge;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Verifies a submitted TOTP code against a CONFIRMED enrolment — the
 * post-enrolment "prove you still have the authenticator" step (design.md
 * enforcement-points list's mechanism; NOT wired into any login flow by
 * this batch — see this module's top-level HUMAN-GATED note).
 *
 * Every call writes exactly one `mfa_challenges` row (this module's own
 * fine-grained attempt log) AND exactly one `audit_events` row via
 * `Audit::record()` (the platform-wide cross-cutting log) — both, always,
 * succeeded or failed, satisfying AC6/AC9's "auditable and rate-limited"
 * for every attempt, not just successes.
 */
final class MfaChallengeService
{
    private const string RATE_LIMIT_CONTEXT = 'mfa-challenge';

    /**
     * @throws MfaEnrolmentNotConfirmedException when `$enrolment` is not
     *                                           `MfaEnrolmentStatus::CONFIRMED`.
     */
    public function challenge(
        MfaEnrolment $enrolment,
        string $submittedCode,
        int $actorRef,
        string $actorRole,
        AuditSource $source,
        string $ip = '0.0.0.0',
    ): MfaChallengeResult {
        if (! $enrolment->isConfirmed()) {
            throw MfaEnrolmentNotConfirmedException::forEnrolment($enrolment->id, $enrolment->status);
        }

        if (MfaRateLimiter::tooManyAttempts(self::RATE_LIMIT_CONTEXT, $actorRef, $ip)) {
            $this->recordAttempt($enrolment, MfaChallengeOutcome::FAILED, $ip);
            $this->auditFailure($enrolment, $actorRef, $actorRole, $source);

            return MfaChallengeResult::rateLimited();
        }

        MfaRateLimiter::hit(self::RATE_LIMIT_CONTEXT, $actorRef, $ip);

        // Built from THIS row's own period_seconds — see
        // `MfaEnrolmentService::confirm()`'s identical comment for why.
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        $result = $totp->verify(
            keyBytes: Base32::decode($enrolment->secret),
            submittedCode: $submittedCode,
            timestamp: CarbonImmutable::now()->getTimestamp(),
            lastVerifiedCounter: $enrolment->last_verified_counter,
            digits: $enrolment->digits,
        );

        if (! $result->valid) {
            $this->recordAttempt($enrolment, MfaChallengeOutcome::FAILED, $ip);
            $this->auditFailure($enrolment, $actorRef, $actorRole, $source);

            return MfaChallengeResult::failure();
        }

        return DB::transaction(function () use ($enrolment, $result, $actorRef, $actorRole, $source, $ip): MfaChallengeResult {
            // Persist the matched counter FIRST, in the same transaction as
            // the success bookkeeping — this is the write that makes replay
            // protection durable across requests (`Totp::verify()`'s own
            // doc block).
            $enrolment->forceFill(['last_verified_counter' => $result->counter])->save();

            MfaChallenge::create([
                'mfa_enrolment_id' => $enrolment->id,
                'method' => MfaVerificationMethod::TOTP,
                'outcome' => MfaChallengeOutcome::SUCCEEDED,
                'ip_address' => $ip,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            Audit::record(
                action: MfaAuditActions::CHALLENGE_SUCCEEDED,
                subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                metadata: ['method' => MfaVerificationMethod::TOTP],
            );

            MfaRateLimiter::clear(self::RATE_LIMIT_CONTEXT, $actorRef, $ip);

            return MfaChallengeResult::success();
        });
    }

    private function recordAttempt(MfaEnrolment $enrolment, string $outcome, string $ip): void
    {
        MfaChallenge::create([
            'mfa_enrolment_id' => $enrolment->id,
            'method' => MfaVerificationMethod::TOTP,
            'outcome' => $outcome,
            'ip_address' => $ip,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function auditFailure(MfaEnrolment $enrolment, int $actorRef, string $actorRole, AuditSource $source): void
    {
        Audit::record(
            action: MfaAuditActions::CHALLENGE_FAILED,
            subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
            outcome: AuditOutcome::Denied,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            metadata: ['method' => MfaVerificationMethod::TOTP],
        );
    }
}
