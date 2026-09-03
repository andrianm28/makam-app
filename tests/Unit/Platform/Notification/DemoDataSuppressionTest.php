<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\DemoDataSuppression;
use Tests\TestCase;

final class DemoDataSuppressionTest extends TestCase
{
    public function test_active_is_false_by_default(): void
    {
        $this->assertFalse(DemoDataSuppression::active());
    }

    public function test_run_makes_active_true_for_the_duration_of_the_callback(): void
    {
        $observedDuringRun = null;

        DemoDataSuppression::run(function () use (&$observedDuringRun): void {
            $observedDuringRun = DemoDataSuppression::active();
        });

        $this->assertTrue($observedDuringRun);
        $this->assertFalse(DemoDataSuppression::active());
    }

    public function test_run_clears_the_flag_even_when_the_callback_throws(): void
    {
        try {
            DemoDataSuppression::run(function (): void {
                throw new \RuntimeException('deliberate failure mid-run');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(DemoDataSuppression::active());
    }

    public function test_run_returns_the_callbacks_return_value(): void
    {
        $result = DemoDataSuppression::run(fn (): string => 'batch-summary');

        $this->assertSame('batch-summary', $result);
    }
}
