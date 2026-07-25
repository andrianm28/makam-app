<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Mfa\Totp;

use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Tests\TestCase;

/**
 * `Totp::verify()`'s replay-rejection and clock-drift-tolerance behaviour —
 * the two properties the batch brief calls out as a "well-known TOTP
 * implementation pitfall" if skipped. Uses the same RFC 6238 secret as
 * `TotpRfc6238VectorsTest` so the codes exercised here are themselves
 * already known-correct, isolating these tests to the `verify()` window/
 * replay logic specifically rather than re-testing code generation.
 */
final class TotpVerifyReplayAndDriftTest extends TestCase
{
    private const string RFC_SECRET = '12345678901234567890';

    public function test_valid_code_at_current_step_is_accepted_with_no_prior_counter(): void
    {
        $totp = new Totp(t0: 0, period: 30);
        $code = $totp->generate(self::RFC_SECRET, 59, digits: 6);

        $result = $totp->verify(self::RFC_SECRET, $code, 59, lastVerifiedCounter: null, digits: 6);

        $this->assertTrue($result->valid);
        $this->assertSame(1, $result->counter);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $totp = new Totp(t0: 0, period: 30);

        $result = $totp->verify(self::RFC_SECRET, '000000', 59, lastVerifiedCounter: null, digits: 6);

        $this->assertFalse($result->valid);
        $this->assertNull($result->counter);
    }

    public function test_the_same_code_cannot_be_replayed_once_its_step_is_already_consumed(): void
    {
        $totp = new Totp(t0: 0, period: 30);
        $code = $totp->generate(self::RFC_SECRET, 59, digits: 6);

        $first = $totp->verify(self::RFC_SECRET, $code, 59, lastVerifiedCounter: null, digits: 6);
        $this->assertTrue($first->valid);

        // Same code, same timestamp, but the counter it maps to has now
        // been consumed (passed back in as $lastVerifiedCounter) — must be
        // rejected even though the code is still mathematically correct
        // for that step and still inside the verification window.
        $replay = $totp->verify(self::RFC_SECRET, $code, 59, lastVerifiedCounter: $first->counter, digits: 6);

        $this->assertFalse($replay->valid);
    }

    public function test_a_step_before_the_last_verified_counter_is_never_accepted_even_within_window(): void
    {
        $totp = new Totp(t0: 0, period: 30);

        // Step for T=59 is 1. Pretend counter 5 was already consumed
        // (actor is currently well ahead) — a code for step 1 must never
        // validate again, even though it is within ±1 of some other
        // hypothetical "current" step.
        $oldCode = $totp->generate(self::RFC_SECRET, 59, digits: 6);

        $result = $totp->verify(self::RFC_SECRET, $oldCode, 59, lastVerifiedCounter: 5, digits: 6);

        $this->assertFalse($result->valid);
    }

    public function test_clock_drift_one_step_behind_is_tolerated_within_window(): void
    {
        $totp = new Totp(t0: 0, period: 30);

        // Server time is one period (30s) ahead of the code's step, but
        // still within the default ±1 step window.
        $codeForPreviousStep = $totp->generate(self::RFC_SECRET, 1111111109, digits: 6);
        $serverNow = 1111111109 + 30;

        $result = $totp->verify(self::RFC_SECRET, $codeForPreviousStep, $serverNow, lastVerifiedCounter: null, digits: 6);

        $this->assertTrue($result->valid);
    }

    public function test_clock_drift_one_step_ahead_is_tolerated_within_window(): void
    {
        $totp = new Totp(t0: 0, period: 30);

        // Server time is one period (30s) behind the code's step.
        $codeForNextStep = $totp->generate(self::RFC_SECRET, 1111111109, digits: 6);
        $serverNow = 1111111109 - 30;

        $result = $totp->verify(self::RFC_SECRET, $codeForNextStep, $serverNow, lastVerifiedCounter: null, digits: 6);

        $this->assertTrue($result->valid);
    }

    public function test_drift_beyond_the_window_is_rejected(): void
    {
        $totp = new Totp(t0: 0, period: 30);

        // Two full periods (60s) away — outside the default ±1 step
        // window — must not validate.
        $codeTwoStepsAway = $totp->generate(self::RFC_SECRET, 1111111109, digits: 6);
        $serverNow = 1111111109 + 60;

        $result = $totp->verify(self::RFC_SECRET, $codeTwoStepsAway, $serverNow, lastVerifiedCounter: null, digits: 6);

        $this->assertFalse($result->valid);
    }

    public function test_forward_progress_is_still_accepted_after_a_previous_step_was_consumed(): void
    {
        $totp = new Totp(t0: 0, period: 30);

        $firstCode = $totp->generate(self::RFC_SECRET, 59, digits: 6);
        $first = $totp->verify(self::RFC_SECRET, $firstCode, 59, lastVerifiedCounter: null, digits: 6);
        $this->assertTrue($first->valid);

        // A genuinely later code (next step) must still validate — replay
        // protection blocks reuse of already-consumed steps, not all
        // future verification.
        $nextCode = $totp->generate(self::RFC_SECRET, 59 + 30, digits: 6);
        $second = $totp->verify(self::RFC_SECRET, $nextCode, 59 + 30, lastVerifiedCounter: $first->counter, digits: 6);

        $this->assertTrue($second->valid);
        $this->assertGreaterThan($first->counter, $second->counter);
    }
}
