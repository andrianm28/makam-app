<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Tests\TestCase;

final class SchedulerCriticalGuardsTest extends TestCase
{
    public function test_the_destructive_booking_draft_purge_is_not_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->doesntExpectOutputToContain('booking:purge-stale-drafts')
            ->assertExitCode(0);
    }

    public function test_the_critical_operational_gaps_alert_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('alert:critical-operational-gaps')
            ->assertExitCode(0);
    }
}
