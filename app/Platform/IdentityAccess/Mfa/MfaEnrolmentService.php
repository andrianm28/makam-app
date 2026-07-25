<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Mfa\Exceptions\MfaEnrolmentNotPendingException;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Models\MfaRecoveryCode;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\OtpAuthUri;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use App\Platform\IdentityAccess\Mfa\Totp\TotpSecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Pending -> confirmed TOTP enrolment lifecycle, plus recovery-code
 * generation on confirmation. S3-T2, HUMAN-GATED (see this module's
 * top-level doc note and `docs/planning/agent-execution-plan.md`) — this
 * class is reachable only by test code and a future controller/Livewire
 * component this batch does not build. Nothing calls it from any existing
 * request/login/panel-access path.
 *
 * ---------------------------------------------------------------------------
 * "One enrolment per actor; revoking/re-enrolling replaces it" — the exact
 * semantics chosen
 * ---------------------------------------------------------------------------
 * `startEnrolment()` ALWAYS supersedes: any existing non-revoked row
 * (pending or confirmed) for the same user is revoked (not deleted —
 * `revoked_at` set, history kept, mirroring `actor_sessions`/
 * `scope_assignments`'s established soft-revoke pattern) in the SAME
 * transaction that creates the new pending row. Rejecting a second
 * `startEnrolment()` call outright (the alternative considered) would mean
 * a user who lost their authenticator app but still remembers "an
 * enrolment exists" has no self-service path to re-enrol — replace-on-
 * start avoids that dead end. This auto-supersede is deliberately NOT
 * audited as `MfaAuditActions::RESET` — that action name is reserved for
 * an explicit, human-initiated, mandatory-reason revoke
 * (`revoke()` below), not routine re-enrolment bookkeeping. Flagged here as
 * a documented trade-off: an old enrolment silently losing validity when a
 * new one starts has no audit row of its own today, only the new
 * enrolment's eventual `MFA_ENROLMENT_CONFIRMED` row.
 *
 * A row is locked (`lockForUpdate()`) while being superseded so two
 * concurrent `startEnrolment()` calls for the same user cannot both
 * "win" and leave two live pending rows — the same pattern
 * `GateActivationRecorder` already established in this codebase.
 */
final class MfaEnrolmentService
{
    /**
     * 10 codes — a conventional count (Google/GitHub/GitLab-style
     * authenticator recovery flows commonly ship 8-10); 10 gives a little
     * more headroom for a family/staff member who redeems codes
     * infrequently, without generating an unwieldy list to save.
     */
    private const int RECOVERY_CODE_COUNT = 10;

    /**
     * 6 digits — see `Totp\Totp`'s own class-level doc block for why.
     */
    private const int TOTP_DIGITS = 6;

    private const int TOTP_PERIOD_SECONDS = 30;

    /**
     * Charset avoiding visually ambiguous characters (no `0`/`O`, no
     * `1`/`I`) for a code a person may need to type from a printed page.
     */
    private const string RECOVERY_CODE_CHARSET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const int RECOVERY_CODE_LENGTH = 10;

    /**
     * Start (or replace) a pending enrolment for the given local `users.id`.
     * Returns the new PENDING row — not yet trusted for any challenge; the
     * caller must display `otpauthUriFor()`/the row's own decrypted
     * `secret` for the actor to scan/enter, then call `confirm()` with the
     * code the actor's authenticator app produces.
     */
    public function startEnrolment(int $userId): MfaEnrolment
    {
        return DB::transaction(function () use ($userId): MfaEnrolment {
            MfaEnrolment::query()
                ->where('user_id', $userId)
                ->where('status', '!=', MfaEnrolmentStatus::REVOKED)
                ->lockForUpdate()
                ->get()
                ->each(function (MfaEnrolment $existing): void {
                    $existing->forceFill([
                        'status' => MfaEnrolmentStatus::REVOKED,
                        'revoked_at' => CarbonImmutable::now(),
                    ])->save();
                });

            return MfaEnrolment::create([
                'user_id' => $userId,
                'secret' => TotpSecret::generate(),
                'status' => MfaEnrolmentStatus::PENDING,
                'digits' => self::TOTP_DIGITS,
                'period_seconds' => self::TOTP_PERIOD_SECONDS,
            ]);
        });
    }

    /**
     * The `otpauth://` URI a future enrolment UI would render as a QR code
     * for `$enrolment` — no QR rendering happens in this module.
     */
    public function otpauthUriFor(MfaEnrolment $enrolment, string $accountLabel, string $issuer = 'Makam.co.id'): string
    {
        return OtpAuthUri::build(
            issuer: $issuer,
            accountLabel: $accountLabel,
            base32Secret: $enrolment->secret,
            digits: $enrolment->digits,
            period: $enrolment->period_seconds,
        );
    }

    /**
     * Prove possession of the pending secret with one valid TOTP code. On
     * success: the enrolment becomes `CONFIRMED`, `RECOVERY_CODE_COUNT`
     * recovery codes are generated and returned in plaintext ONCE (never
     * persisted in plaintext — each is hashed via `Hash::make()` before
     * being stored), and an `MFA_ENROLMENT_CONFIRMED` audit row is written.
     * On failure: an `MFA_ENROLMENT_CONFIRMED` audit row with
     * `AuditOutcome::Denied` is written and no state changes.
     *
     * @throws MfaEnrolmentNotPendingException when `$enrolment` is not
     *                                         `MfaEnrolmentStatus::PENDING`
     *                                         (already confirmed, or
     *                                         revoked) — a caller bug, not
     *                                         a user-facing "wrong code".
     */
    public function confirm(
        MfaEnrolment $enrolment,
        string $submittedCode,
        int $actorRef,
        string $actorRole,
        AuditSource $source,
    ): MfaEnrolmentConfirmation {
        if (! $enrolment->isPending()) {
            throw MfaEnrolmentNotPendingException::forEnrolment($enrolment->id, $enrolment->status);
        }

        // Built from THIS row's own period_seconds (not a service-level
        // constant) — every enrolment this service creates uses the same
        // `TOTP_PERIOD_SECONDS` value today, but the row's own column is
        // what's authoritative (see the migration's own doc block: "a
        // historical enrolment's parameters remain self-describing even if
        // this module's own defaults ever change").
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        $result = $totp->verify(
            keyBytes: Base32::decode($enrolment->secret),
            submittedCode: $submittedCode,
            timestamp: CarbonImmutable::now()->getTimestamp(),
            lastVerifiedCounter: $enrolment->last_verified_counter,
            digits: $enrolment->digits,
        );

        if (! $result->valid) {
            Audit::record(
                action: MfaAuditActions::ENROLMENT_CONFIRMED,
                subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
                outcome: AuditOutcome::Denied,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
            );

            return MfaEnrolmentConfirmation::failure();
        }

        return DB::transaction(function () use ($enrolment, $result, $actorRef, $actorRole, $source): MfaEnrolmentConfirmation {
            $enrolment->forceFill([
                'status' => MfaEnrolmentStatus::CONFIRMED,
                'confirmed_at' => CarbonImmutable::now(),
                'last_verified_counter' => $result->counter,
            ])->save();

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
                action: MfaAuditActions::ENROLMENT_CONFIRMED,
                subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                metadata: ['recovery_codes_remaining' => count($plaintextCodes)],
            );

            return MfaEnrolmentConfirmation::success($enrolment->fresh() ?? $enrolment, $plaintextCodes);
        });
    }

    /**
     * Explicit, human-reasoned revoke — distinct from `startEnrolment()`'s
     * silent supersede (see class doc block). Requires a non-blank
     * `$reason`: `MFA_RESET` is on `Audit\SensitiveActions::ACTIONS`.
     */
    public function revoke(
        MfaEnrolment $enrolment,
        int $actorRef,
        string $actorRole,
        string $reason,
        AuditSource $source,
    ): MfaEnrolment {
        return DB::transaction(function () use ($enrolment, $actorRef, $actorRole, $reason, $source): MfaEnrolment {
            $enrolment->forceFill([
                'status' => MfaEnrolmentStatus::REVOKED,
                'revoked_at' => CarbonImmutable::now(),
            ])->save();

            Audit::record(
                action: MfaAuditActions::RESET,
                subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                reason: $reason,
            );

            return $enrolment;
        });
    }

    /**
     * `random_int()` (CSPRNG-backed) rather than `Str::random()` — keeps
     * this module's own random-generation story self-contained rather than
     * relying on a helper's internal implementation, matching
     * `Totp\TotpSecret::generate()`'s identical `random_bytes()` choice for
     * the TOTP secret itself. ~50 bits of entropy per code
     * (32^10), which combined with hashed-at-rest storage and
     * `MfaRateLimiter`'s throttle on redemption attempts is more than
     * adequate for a one-time recovery code.
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
}
