<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Jobs\RetryFailedDeliveryJob;
use Tests\TestCase;

/**
 * NOTIF-05 (`docs/superpowers/plans/2026-09-07-batchm8b-notification-
 * completeness.md`): before this fix, `RetryFailedDeliveryJob`'s bounded
 * retry spanned roughly 7 seconds across 3 attempts — any provider blip
 * longer than that permanently failed every delivery in flight. This test
 * asserts the fixed window is genuinely measured in minutes, not seconds.
 */
final class RetryFailedDeliveryJobBackoffTest extends TestCase
{
    public function test_max_attempts_was_raised_to_six(): void
    {
        $this->assertSame(6, RetryFailedDeliveryJob::MAX_ATTEMPTS);
    }

    /**
     * `backoffSeconds()` includes a random jitter term, so this asserts on
     * the UN-jittered floor for each attempt (jitter only ever ADDS delay,
     * never subtracts it) — the minimum possible summed window across all
     * `MAX_ATTEMPTS` retries must still be several minutes, not seconds.
     */
    public function test_the_summed_backoff_window_across_all_attempts_spans_several_minutes(): void
    {
        $floorSum = 0;

        for ($attempt = 1; $attempt <= RetryFailedDeliveryJob::MAX_ATTEMPTS; $attempt++) {
            // The un-jittered base for this attempt, mirroring the
            // production formula's own base calculation exactly.
            $base = min(300, 30 * 2 ** max(0, $attempt - 1));
            $floorSum += $base;

            // Every real sample for this attempt must be at least the
            // floor and at most the documented cap.
            $sample = RetryFailedDeliveryJob::backoffSeconds($attempt);
            $this->assertGreaterThanOrEqual($base, $sample);
            $this->assertLessThanOrEqual(300, $sample);
        }

        // 30 + 60 + 120 + 240 + 300 + 300 = 1050 seconds (~17.5 minutes).
        $this->assertSame(1050, $floorSum);
        $this->assertGreaterThanOrEqual(900, $floorSum, 'The retry window must span at least several minutes.');
    }

    public function test_the_delay_is_capped_at_five_minutes_even_for_a_very_high_attempt_number(): void
    {
        $this->assertLessThanOrEqual(300, RetryFailedDeliveryJob::backoffSeconds(20));
    }
}
