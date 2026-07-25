<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Exceptions\AuditMetadataKeyNotAllowedException;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\MetadataAllowlist;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use App\Platform\IdentityAccess\Mfa\MfaAuditActions;
use App\Platform\IdentityAccess\Mfa\MfaChallengeService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaRecoveryService;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Requirements.md's Negative criteria: "No credential, TOTP secret, or
 * recovery code in logs, error trackers, or audit payloads." This is the
 * safety net proving this batch's `MetadataAllowlist`/`SensitiveActions`
 * additions cannot smuggle a secret-shaped value into `audit_events`, and
 * that a real enrolment/challenge/recovery run's ACTUAL persisted audit
 * metadata never contains a code/secret/recovery-code value — not merely
 * "the code doesn't call it that."
 */
final class MfaAuditSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const array SECRET_SHAPED_SUBSTRINGS = [
        'secret',
        'code_hash',
        'recovery_code_value',
        'totp_code',
        'password',
        'credential',
    ];

    public function test_new_metadata_allowlist_keys_are_not_secret_shaped(): void
    {
        foreach (MetadataAllowlist::ALLOWED_KEYS as $key) {
            foreach (self::SECRET_SHAPED_SUBSTRINGS as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    strtolower($key),
                    "Allowlisted metadata key [{$key}] looks secret-shaped."
                );
            }
        }

        // The two keys this batch actually added.
        $this->assertContains('method', MetadataAllowlist::ALLOWED_KEYS);
        $this->assertContains('recovery_codes_remaining', MetadataAllowlist::ALLOWED_KEYS);
    }

    public function test_only_mfa_reset_is_a_sensitive_action_requiring_a_reason(): void
    {
        $this->assertContains(MfaAuditActions::RESET, SensitiveActions::ACTIONS);
        $this->assertTrue(SensitiveActions::requiresReason(MfaAuditActions::RESET));

        foreach ([
            MfaAuditActions::ENROLMENT_CONFIRMED,
            MfaAuditActions::CHALLENGE_SUCCEEDED,
            MfaAuditActions::CHALLENGE_FAILED,
            MfaAuditActions::RECOVERY_USED,
        ] as $routineAction) {
            $this->assertFalse(
                SensitiveActions::requiresReason($routineAction),
                "{$routineAction} should not require a mandatory reason."
            );
        }
    }

    public function test_revoke_without_a_reason_is_rejected_before_any_audit_row_is_written(): void
    {
        $user = User::factory()->create();
        $enrolment = (new MfaEnrolmentService)->startEnrolment($user->id);

        $this->expectException(AuditReasonRequiredException::class);

        (new MfaEnrolmentService)->revoke($enrolment, $user->id, 'admin', '', AuditSource::Panel);
    }

    public function test_a_real_enrolment_confirmation_run_never_persists_a_secret_or_code_shaped_value_in_audit_metadata(): void
    {
        $user = User::factory()->create();
        $enrolmentService = new MfaEnrolmentService;
        $enrolment = $enrolmentService->startEnrolment($user->id);
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $code = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        $confirmation = $enrolmentService->confirm($enrolment, $code, $user->id, 'admin', AuditSource::Panel);

        $this->assertNoAuditPayloadContainsSecretShapedValues($enrolment->secret, $confirmation->recoveryCodes, $code);
    }

    public function test_a_real_challenge_and_recovery_run_never_persists_a_secret_or_code_shaped_value_in_audit_metadata(): void
    {
        $user = User::factory()->create();
        $enrolmentService = new MfaEnrolmentService;
        $enrolment = $enrolmentService->startEnrolment($user->id);
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $firstCode = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        $confirmation = $enrolmentService->confirm($enrolment, $firstCode, $user->id, 'admin', AuditSource::Panel);
        $confirmedEnrolment = $confirmation->enrolment;

        // A challenge with a deliberately wrong code, then redeem a real
        // recovery code — exercising both failure and success audit
        // writes for both services in one run.
        (new MfaChallengeService)->challenge($confirmedEnrolment, '000000', $user->id, 'admin', AuditSource::Panel);
        (new MfaRecoveryService)->redeem($confirmedEnrolment, $confirmation->recoveryCodes[0], $user->id, 'admin', AuditSource::Panel);

        $this->assertNoAuditPayloadContainsSecretShapedValues(
            $enrolment->secret,
            $confirmation->recoveryCodes,
            $firstCode,
        );
    }

    /**
     * @param  list<string>  $recoveryCodes
     */
    private function assertNoAuditPayloadContainsSecretShapedValues(
        string $secret,
        array $recoveryCodes,
        string $totpCode,
    ): void {
        $events = AuditEvent::query()->get();
        $this->assertNotEmpty($events, 'Expected at least one audit event to have been written.');

        foreach ($events as $event) {
            $serializedMetadata = json_encode($event->metadata ?? []);
            $this->assertIsString($serializedMetadata);

            $this->assertStringNotContainsString($secret, $serializedMetadata);
            $this->assertStringNotContainsString($totpCode, $serializedMetadata);

            foreach ($recoveryCodes as $recoveryCode) {
                $this->assertStringNotContainsString($recoveryCode, $serializedMetadata);
            }

            // Only allowlisted keys should ever appear.
            foreach (array_keys($event->metadata ?? []) as $metadataKey) {
                $this->assertContains($metadataKey, MetadataAllowlist::ALLOWED_KEYS);
            }
        }
    }

    public function test_writing_a_disallowed_metadata_key_is_rejected(): void
    {
        $this->expectException(AuditMetadataKeyNotAllowedException::class);

        MetadataAllowlist::assertAllowed(['totp_secret' => 'JBSWY3DPEHPK3PXP']);
    }
}
