<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Mfa\Exceptions\MfaEnrolmentNotConfirmedException;
use App\Platform\IdentityAccess\Mfa\MfaAuditActions;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaRecoveryService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Models\MfaRecoveryCode;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `MfaRecoveryService::redeem()` — single-use consumption of a hashed
 * recovery code, the distinct "no codes remaining" state, and rate
 * limiting on redemption attempts.
 */
final class MfaRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: MfaEnrolment, 1: list<string>}
     */
    private function confirmedEnrolmentWithRecoveryCodesFor(User $user): array
    {
        $enrolmentService = new MfaEnrolmentService;
        $enrolment = $enrolmentService->startEnrolment($user->id);
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $code = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        $confirmation = $enrolmentService->confirm($enrolment, $code, $user->id, 'admin', AuditSource::Panel);

        return [$confirmation->enrolment, $confirmation->recoveryCodes];
    }

    public function test_a_valid_unused_recovery_code_succeeds_and_is_marked_used(): void
    {
        $user = User::factory()->create();
        [$enrolment, $recoveryCodes] = $this->confirmedEnrolmentWithRecoveryCodesFor($user);
        $codeToRedeem = $recoveryCodes[0];

        $service = new MfaRecoveryService;
        $result = $service->redeem($enrolment, $codeToRedeem, $user->id, 'admin', AuditSource::Panel, '203.0.113.5');

        $this->assertTrue($result->valid);
        $this->assertFalse($result->noCodesRemaining);

        $usedCount = MfaRecoveryCode::query()
            ->where('mfa_enrolment_id', $enrolment->id)
            ->whereNotNull('used_at')
            ->count();
        $this->assertSame(1, $usedCount);

        $auditEvent = AuditEvent::query()->where('action', MfaAuditActions::RECOVERY_USED)->latest('id')->first();
        $this->assertNotNull($auditEvent);
        $this->assertSame(AuditOutcome::Allowed->value, $auditEvent->outcome);
        $this->assertSame(9, $auditEvent->metadata['recovery_codes_remaining']);
    }

    public function test_a_recovery_code_cannot_be_redeemed_twice(): void
    {
        $user = User::factory()->create();
        [$enrolment, $recoveryCodes] = $this->confirmedEnrolmentWithRecoveryCodesFor($user);
        $codeToRedeem = $recoveryCodes[0];
        $service = new MfaRecoveryService;

        $first = $service->redeem($enrolment, $codeToRedeem, $user->id, 'admin', AuditSource::Panel, '203.0.113.5');
        $this->assertTrue($first->valid);

        $second = $service->redeem($enrolment, $codeToRedeem, $user->id, 'admin', AuditSource::Panel, '203.0.113.6');
        $this->assertFalse($second->valid);
        $this->assertFalse($second->noCodesRemaining);
    }

    public function test_an_unknown_code_fails_without_consuming_anything(): void
    {
        $user = User::factory()->create();
        [$enrolment] = $this->confirmedEnrolmentWithRecoveryCodesFor($user);

        $service = new MfaRecoveryService;
        $result = $service->redeem($enrolment, 'NOTREAL-CODE1', $user->id, 'admin', AuditSource::Panel);

        $this->assertFalse($result->valid);
        $this->assertSame(0, MfaRecoveryCode::query()->where('mfa_enrolment_id', $enrolment->id)->whereNotNull('used_at')->count());
    }

    public function test_exhausting_every_recovery_code_reports_the_distinct_no_codes_remaining_state(): void
    {
        $user = User::factory()->create();
        [$enrolment, $recoveryCodes] = $this->confirmedEnrolmentWithRecoveryCodesFor($user);
        $service = new MfaRecoveryService;

        // Consume every code, alternating IPs so the rate limiter never
        // interferes with this test's own assertion.
        foreach ($recoveryCodes as $index => $recoveryCode) {
            $result = $service->redeem($enrolment, $recoveryCode, $user->id, 'admin', AuditSource::Panel, "203.0.113.{$index}");
            $this->assertTrue($result->valid);
        }

        $afterExhaustion = $service->redeem($enrolment, 'ANY-CODE99', $user->id, 'admin', AuditSource::Panel, '203.0.113.250');

        $this->assertFalse($afterExhaustion->valid);
        $this->assertTrue($afterExhaustion->noCodesRemaining);
    }

    public function test_redeeming_against_a_pending_enrolment_throws(): void
    {
        $user = User::factory()->create();
        $enrolment = (new MfaEnrolmentService)->startEnrolment($user->id);

        $this->expectException(MfaEnrolmentNotConfirmedException::class);

        (new MfaRecoveryService)->redeem($enrolment, 'ANYCODE123', $user->id, 'admin', AuditSource::Panel);
    }

    public function test_rate_limiter_blocks_recovery_attempts_after_the_documented_threshold(): void
    {
        $user = User::factory()->create();
        [$enrolment, $recoveryCodes] = $this->confirmedEnrolmentWithRecoveryCodesFor($user);
        $service = new MfaRecoveryService;
        $ip = '198.51.100.42';

        for ($i = 0; $i < 5; $i++) {
            $result = $service->redeem($enrolment, 'WRONG-CODE1', $user->id, 'admin', AuditSource::Panel, $ip);
            $this->assertFalse($result->valid);
            $this->assertFalse($result->rateLimited);
        }

        $sixth = $service->redeem($enrolment, $recoveryCodes[0], $user->id, 'admin', AuditSource::Panel, $ip);

        $this->assertFalse($sixth->valid);
        $this->assertTrue($sixth->rateLimited);
    }

    public function test_regenerate_invalidates_old_unused_codes_and_returns_ten_new_ones(): void
    {
        $user = User::factory()->create();
        [$enrolment] = $this->confirmedEnrolmentWithRecoveryCodesFor($user);
        $oldCodes = MfaRecoveryCode::query()->where('mfa_enrolment_id', $enrolment->id)->get();
        $this->assertCount(10, $oldCodes);

        $newCodes = app(MfaRecoveryService::class)->regenerate(
            enrolment: $enrolment,
            actorRef: $enrolment->user_id,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );

        $this->assertCount(10, $newCodes);

        // Every old code is now unusable, even though it was never redeemed.
        foreach ($oldCodes as $old) {
            $this->assertNotNull($old->fresh()->used_at);
        }

        // The new codes are real, unused, and distinct from the old plaintext values.
        $freshCodes = $enrolment->recoveryCodes()->whereNull('used_at')->get();
        $this->assertCount(10, $freshCodes);
        foreach ($newCodes as $plaintext) {
            $this->assertTrue(
                $freshCodes->contains(fn (MfaRecoveryCode $c): bool => Hash::check($plaintext, $c->code_hash))
            );
        }
    }

    public function test_regenerate_writes_one_audit_event(): void
    {
        $user = User::factory()->create();
        [$enrolment] = $this->confirmedEnrolmentWithRecoveryCodesFor($user);

        app(MfaRecoveryService::class)->regenerate(
            enrolment: $enrolment,
            actorRef: $enrolment->user_id,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => MfaAuditActions::RECOVERY_CODES_REGENERATED,
            'outcome' => AuditOutcome::Allowed->value,
        ]);
    }
}
