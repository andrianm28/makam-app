<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Platform\Correlation\Concerns\CarriesCorrelationId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Test-only fixture proving `CarriesCorrelationId` works against a minimal
 * `ShouldQueue` job, per this batch's brief: "prove the trait's mechanism
 * in isolation ... not a real end-to-end dispatch-to-Horizon-worker path"
 * — mirrors the precedent `tests/Fixtures/ScopedTestModel.php` set for
 * `HasScopeAssignments` (prove the mechanism against a fixture, because no
 * real domain consumer exists yet).
 *
 * Deliberately lives under `tests/Fixtures/`, not `app/**` — never
 * autoloaded in production, and not a claim that any real job class exists
 * or adopts this trait. See this batch's report / `CarriesCorrelationId`'s
 * own doc block for that gap stated explicitly.
 */
final class CorrelatedTestJob implements ShouldQueue
{
    use CarriesCorrelationId;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $handled = false;

    public function __construct()
    {
        $this->captureCorrelationContext();
    }

    public function handle(): void
    {
        $this->restoreCorrelationContext();

        $this->handled = true;
    }
}
