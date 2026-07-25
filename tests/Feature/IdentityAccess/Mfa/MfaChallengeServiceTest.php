<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Mfa\Exceptions\MfaEnrolmentNotConfirmedException;
use App\Platform\IdentityAccess\Mfa\MfaAuditActions;
use App\Platform\IdentityAccess\Mfa\MfaChallengeOutcome;
use App\Platform\IdentityAccess\Mfa\MfaChallengeService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\Models\MfaChallenge;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `MfaChallengeService::challenge()` — verifying a TOTP code against a
 * CONFIRMED enrolment: success/failure recording, replay rejection
 * (delegated to, and re-proven through, `Totp::verify()`), and the rate
 * limit actually blocking after the documented threshold (5/60s,
 * `MfaRateLimiter`).
 */
final class MfaChallengeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedEnrolmentFor(User $user): MfaEnrolment
    {
        $enrolmentService = new MfaEnrolmentService;
        $enrolment = $enrolmentService->startEnrolment($user->id);
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $code = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        return $enrolmentService->confirm($enrolment, $code, $user->id, 'admin', AuditSource::Panel)->enrolment;
    }

    /**
     * FIXED 26 Jul 2026 — first real CI run: `confirmedEnrolmentFor()`
     * already consumes the CURRENT time-step as `last_verified_counter`
     * (confirmation itself is a real TOTP verification). Generating this
     * helper's code for `time()` too meant it very often (depending on
     * exactly where in the 30-second window the test happened to run)
     * asked for a code on the SAME step confirmation had just consumed —
     * `Totp::verify()`'s replay guard then correctly rejected it as a
     * replay, failing `$result->valid` for a reason that had nothing to
     * do with a real defect (the replay protection was doing exactly what
     * it is supposed to). Generating one period AHEAD guarantees a step
     * strictly greater than whatever confirmation just consumed, while
     * still landing inside `verify()`'s default ±1-step tolerance window
     * when the challenge actually runs moments later at real "now".
     */
    private function currentCodeFor(MfaEnrolment $enrolment): string
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        return $totp->generate(Base32::decode($enrolment->secret), time() + $enrolment->period_seconds, $enrolment->digits);
    }

    public function test_a_valid_code_succeeds_and_records_a_challenge_and_audit_row(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $code = $this->currentCodeFor($enrolment);

        $service = new MfaChallengeService;
        $result = $service->challenge($enrolment, $code, $user->id, 'admin', AuditSource::Panel, '203.0.113.1');

        $this->assertTrue($result->valid);
        $this->assertFalse($result->rateLimited);

        $challenge = MfaChallenge::query()->where('mfa_enrolment_id', $enrolment->id)->latest('id')->first();
        $this->assertNotNull($challenge);
        $this->assertSame(MfaChallengeOutcome::SUCCEEDED, $challenge->outcome);
        // The submitted code has no column to leak into at all — assert
        // the row's own attribute set never grew one.
        $this->assertArrayNotHasKey('code', $challenge->getAttributes());
        $this->assertArrayNotHasKey('secret', $challenge->getAttributes());

        $auditEvent = AuditEvent::query()->where('action', MfaAuditActions::CHALLENGE_SUCCEEDED)->latest('id')->first();
        $this->assertNotNull($auditEvent);
        $this->assertSame(AuditOutcome::Allowed->value, $auditEvent->outcome);
    }

    public function test_an_invalid_code_fails_and_records_a_denied_audit_row(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);

        $service = new MfaChallengeService;
        $result = $service->challenge($enrolment, '000000', $user->id, 'admin', AuditSource::Panel, '203.0.113.1');

        $this->assertFalse($result->valid);

        $challenge = MfaChallenge::query()->where('mfa_enrolment_id', $enrolment->id)->latest('id')->first();
        $this->assertSame(MfaChallengeOutcome::FAILED, $challenge->outcome);

        $auditEvent = AuditEvent::query()->where('action', MfaAuditActions::CHALLENGE_FAILED)->latest('id')->first();
        $this->assertSame(AuditOutcome::Denied->value, $auditEvent->outcome);
    }

    public function test_challenging_a_pending_enrolment_throws(): void
    {
        $user = User::factory()->create();
        $enrolment = (new MfaEnrolmentService)->startEnrolment($user->id);

        $this->expectException(MfaEnrolmentNotConfirmedException::class);

        (new MfaChallengeService)->challenge($enrolment, '123456', $user->id, 'admin', AuditSource::Panel);
    }

    public function test_the_same_code_cannot_be_replayed_against_a_confirmed_enrolment(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $code = $this->currentCodeFor($enrolment);

        $service = new MfaChallengeService;
        $first = $service->challenge($enrolment, $code, $user->id, 'admin', AuditSource::Panel, '203.0.113.1');
        $this->assertTrue($first->valid);

        $enrolment->refresh();
        $replay = $service->challenge($enrolment, $code, $user->id, 'admin', AuditSource::Panel, '203.0.113.2');

        $this->assertFalse($replay->valid);
    }

    public function test_rate_limiter_blocks_after_the_documented_threshold(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $service = new MfaChallengeService;
        $ip = '198.51.100.7';

        // 5 wrong attempts consume the whole bucket (MfaRateLimiter's
        // documented 5-attempts-per-60-seconds threshold).
        for ($i = 0; $i < 5; $i++) {
            $result = $service->challenge($enrolment, '000000', $user->id, 'admin', AuditSource::Panel, $ip);
            $this->assertFalse($result->valid);
            $this->assertFalse($result->rateLimited, "Attempt {$i} should not itself report rate-limited.");
        }

        // The 6th attempt — even with a code that WOULD be correct — must
        // be refused for being rate-limited, not evaluated at all.
        $code = $this->currentCodeFor($enrolment);
        $sixth = $service->challenge($enrolment, $code, $user->id, 'admin', AuditSource::Panel, $ip);

        $this->assertFalse($sixth->valid);
        $this->assertTrue($sixth->rateLimited);
    }

    public function test_rate_limit_is_scoped_per_ip_so_a_different_ip_is_not_blocked(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $service = new MfaChallengeService;

        for ($i = 0; $i < 5; $i++) {
            $service->challenge($enrolment, '000000', $user->id, 'admin', AuditSource::Panel, '198.51.100.7');
        }

        $code = $this->currentCodeFor($enrolment);
        $result = $service->challenge($enrolment, $code, $user->id, 'admin', AuditSource::Panel, '198.51.100.99');

        $this->assertTrue($result->valid);
    }

    public function test_a_successful_challenge_clears_the_rate_limit_counter(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $service = new MfaChallengeService;
        $ip = '198.51.100.50';

        $code = $this->currentCodeFor($enrolment);
        $service->challenge($enrolment, $code, $user->id, 'admin', AuditSource::Panel, $ip);

        // If the counter were not cleared, 5 more failed attempts would
        // already be at the threshold; confirm one more failed attempt
        // is still evaluated as a normal failure, not a rate-limit refusal.
        $enrolment->refresh();
        $result = $service->challenge($enrolment, '000000', $user->id, 'admin', AuditSource::Panel, $ip);

        $this->assertFalse($result->valid);
        $this->assertFalse($result->rateLimited);
    }
}
