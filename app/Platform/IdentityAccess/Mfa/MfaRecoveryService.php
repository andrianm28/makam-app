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
use App\Platform\IdentityAccess\Mfa\Models\MfaRecoveryCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Verifies a submitted recovery code against a CONFIRMED enrolment's
 * unused, hashed codes, and consumes it on match — single-use, never
 * reusable (requirements.md's account-recovery expectation;
 * `docs/security/authentication-and-mfa.md` §9: "Recovery codes are
 * one-time and stored hashed where possible").
 *
 * "No recovery codes left" is reported as its OWN distinct result
 * (`MfaRecoveryResult::noCodesRemaining()`), never conflated with a wrong
 * code — the batch brief's explicit instruction, so a future UI can tell
 * an actor "you're out of recovery codes, contact support/re-enrol"
 * instead of the misleading "that code is wrong."
 */
final class MfaRecoveryService
{
    private const string RATE_LIMIT_CONTEXT = 'mfa-recovery';

    /**
     * 10 codes — a conventional count (Google/GitHub/GitLab-style
     * authenticator recovery flows commonly ship 8-10); 10 gives a little
     * more headroom for a family/staff member who redeems codes
     * infrequently, without generating an unwieldy list to save.
     *
     * Duplicated from MfaEnrolmentService due to it being a private constant
     * there — see regenerate() below for the rationale.
     */
    private const int RECOVERY_CODE_COUNT = 10;

    /**
     * Charset avoiding visually ambiguous characters (no `0`/`O`, no
     * `1`/`I`) for a code a person may need to type from a printed page.
     *
     * Duplicated from MfaEnrolmentService — see regenerate() below for the rationale.
     */
    private const string RECOVERY_CODE_CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const int RECOVERY_CODE_LENGTH = 10;

    /**
     * @throws MfaEnrolmentNotConfirmedException when `$enrolment` is not
     *                                           `MfaEnrolmentStatus::CONFIRMED`.
     */
    public function redeem(
        MfaEnrolment $enrolment,
        string $submittedCode,
        int $actorRef,
        string $actorRole,
        AuditSource $source,
        string $ip = '0.0.0.0',
    ): MfaRecoveryResult {
        if (! $enrolment->isConfirmed()) {
            throw MfaEnrolmentNotConfirmedException::forEnrolment($enrolment->id, $enrolment->status);
        }

        if (MfaRateLimiter::tooManyAttempts(self::RATE_LIMIT_CONTEXT, $actorRef, $ip)) {
            $this->recordAttempt($enrolment, MfaChallengeOutcome::FAILED, $ip);
            $this->auditDenied($enrolment, $actorRef, $actorRole, $source, remaining: null);

            return MfaRecoveryResult::rateLimited();
        }

        MfaRateLimiter::hit(self::RATE_LIMIT_CONTEXT, $actorRef, $ip);

        $unusedCodes = $enrolment->recoveryCodes()->whereNull('used_at')->get();

        if ($unusedCodes->isEmpty()) {
            $this->auditDenied($enrolment, $actorRef, $actorRole, $source, remaining: 0);

            return MfaRecoveryResult::noCodesRemaining();
        }

        /** @var MfaRecoveryCode|null $matched */
        $matched = $unusedCodes->first(
            fn (MfaRecoveryCode $candidate): bool => Hash::check($submittedCode, $candidate->code_hash)
        );

        if ($matched === null) {
            $this->recordAttempt($enrolment, MfaChallengeOutcome::FAILED, $ip);
            $this->auditDenied($enrolment, $actorRef, $actorRole, $source, remaining: null);

            return MfaRecoveryResult::failure();
        }

        return DB::transaction(function () use ($enrolment, $matched, $actorRef, $actorRole, $source, $ip): MfaRecoveryResult {
            $matched->markUsed();

            MfaChallenge::create([
                'mfa_enrolment_id' => $enrolment->id,
                'method' => MfaVerificationMethod::RECOVERY_CODE,
                'outcome' => MfaChallengeOutcome::SUCCEEDED,
                'ip_address' => $ip,
                'occurred_at' => CarbonImmutable::now(),
            ]);

            $remaining = $enrolment->recoveryCodes()->whereNull('used_at')->count();

            Audit::record(
                action: MfaAuditActions::RECOVERY_USED,
                subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                metadata: [
                    'method' => MfaVerificationMethod::RECOVERY_CODE,
                    'recovery_codes_remaining' => $remaining,
                ],
            );

            MfaRateLimiter::clear(self::RATE_LIMIT_CONTEXT, $actorRef, $ip);

            return MfaRecoveryResult::success();
        });
    }

    /**
     * Invalidates every currently-unused recovery code for `$enrolment` and
     * generates a fresh batch of RECOVERY_CODE_COUNT — the standard
     * "regenerate" semantics: old codes stop working the moment new ones
     * exist, mirroring MfaEnrolmentService::confirm()'s one-time plaintext
     * display contract. Old codes are marked used (not deleted) — same
     * soft-invalidate-don't-delete convention this module already uses for
     * MfaEnrolment::REVOKED.
     *
     * @return list<string>
     */
    public function regenerate(
        MfaEnrolment $enrolment,
        int $actorRef,
        string $actorRole,
        AuditSource $source,
    ): array {
        return DB::transaction(function () use ($enrolment, $actorRef, $actorRole, $source): array {
            $enrolment->recoveryCodes()->whereNull('used_at')->get()->each(
                fn (MfaRecoveryCode $code) => $code->markUsed()
            );

            $plaintextCodes = [];

            for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
                $code = self::generateRecoveryCode();
                $plaintextCodes[] = $code;

                MfaRecoveryCode::create([
                    'mfa_enrolment_id' => $enrolment->id,
                    'code_hash' => Hash::make($code),
                ]);
            }

            Audit::record(
                action: MfaAuditActions::RECOVERY_CODES_REGENERATED,
                subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
            );

            return $plaintextCodes;
        });
    }

    /**
     * `random_int()` (CSPRNG-backed) rather than `Str::random()` — keeps
     * this module's own random-generation story self-contained rather than
     * relying on a helper's internal implementation, matching
     * `MfaEnrolmentService::generateRecoveryCode()`'s identical approach.
     * ~50 bits of entropy per code (32^10), which combined with hashed-at-rest
     * storage and `MfaRateLimiter`'s throttle on redemption attempts is more
     * than adequate for a one-time recovery code.
     *
     * Duplicated from MfaEnrolmentService (not extracted to a shared helper)
     * — both are small ~15-line private methods; extracting a dedicated class
     * would add unnecessary indirection for two call sites in different
     * services. Duplication is noted here and in the RECOVERY_CODE_*
     * constants above.
     */
    private static function generateRecoveryCode(): string
    {
        $charsetLength = strlen(self::RECOVERY_CODE_CHARSET);
        $code = '';

        for ($i = 0; $i < self::RECOVERY_CODE_LENGTH; $i++) {
            $code .= self::RECOVERY_CODE_CHARSET[random_int(0, $charsetLength - 1)];
        }

        return substr($code, 0, 5).'-'.substr($code, 5);
    }

    private function recordAttempt(MfaEnrolment $enrolment, string $outcome, string $ip): void
    {
        MfaChallenge::create([
            'mfa_enrolment_id' => $enrolment->id,
            'method' => MfaVerificationMethod::RECOVERY_CODE,
            'outcome' => $outcome,
            'ip_address' => $ip,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function auditDenied(
        MfaEnrolment $enrolment,
        int $actorRef,
        string $actorRole,
        AuditSource $source,
        ?int $remaining,
    ): void {
        $metadata = ['method' => MfaVerificationMethod::RECOVERY_CODE];

        if ($remaining !== null) {
            $metadata['recovery_codes_remaining'] = $remaining;
        }

        Audit::record(
            action: MfaAuditActions::CHALLENGE_FAILED,
            subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
            outcome: AuditOutcome::Denied,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            metadata: $metadata,
        );
    }
}
