<?php

declare(strict_types=1);

namespace Tests\Feature\Correlation;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use Tests\Fixtures\CorrelatedTestJob;
use Tests\TestCase;

/**
 * Proves `CarriesCorrelationId`'s own mechanism — capture-at-construction,
 * restore-at-handle — against the test-only `CorrelatedTestJob` fixture.
 *
 * ---------------------------------------------------------------------------
 * What this DOES and does NOT prove — read before trusting it further than
 * this
 * ---------------------------------------------------------------------------
 * This proves the trait's in-process capture/restore mechanism works in
 * isolation: construct a job while a correlation id is bound, simulate a
 * fresh worker process by discarding the container's scoped instances
 * (exactly what `Illuminate\Queue\QueueServiceProvider` does between real
 * Horizon jobs), call the job's own `handle()`, and check the id came back.
 *
 * It does NOT prove a real end-to-end dispatch-to-Horizon-worker path.
 * `CorrelatedTestJob` is a fixture created solely for this test (see its
 * own doc block) — no real `ShouldQueue` job class exists anywhere in this
 * repository yet to prove that against, and Horizon does not run in CI.
 * Serialization across an actual queue connection (Redis) is also not
 * exercised here; `QUEUE_CONNECTION=sync` in this test environment means a
 * dispatched job would run inline in the same process regardless, which
 * would not prove cross-process behavior even if this test did dispatch
 * one. Stated explicitly rather than implied away.
 */
final class CarriesCorrelationIdJobTest extends TestCase
{
    public function test_a_correlation_id_captured_at_construction_survives_a_simulated_process_boundary_and_is_restored_in_handle(): void
    {
        // 1. Bind a correlation id, as AssignCorrelationId would for a real
        //    request that goes on to dispatch this job.
        $originalId = CorrelationId::fromString('original-correlation-id');
        $this->app->make(CorrelationContext::class)->set($originalId);

        // 2. Construct the job in THIS (still same) process — captures the
        //    id via CarriesCorrelationId::captureCorrelationContext().
        $job = new CorrelatedTestJob;

        // 3. Simulate "a new worker process picks this job up": discard the
        //    scoped CorrelationContext the same way
        //    QueueServiceProvider does between real jobs, so the fresh
        //    instance has nothing bound.
        $this->app->forgetScopedInstances();
        $this->assertNull($this->app->make(CorrelationContext::class)->current());

        // 4. Run the job for real — its own handle() calls
        //    restoreCorrelationContext() as its first line.
        $job->handle();

        $this->assertTrue($job->handled);
        $restored = $this->app->make(CorrelationContext::class)->current();

        $this->assertNotNull($restored);
        $this->assertSame($originalId->value, $restored->value);
    }

    public function test_a_job_constructed_with_no_correlation_id_bound_restores_to_a_safe_no_op(): void
    {
        // No CorrelationContext::set() call before construction — proves
        // the trait never crashes or invents an id when there was nothing
        // to capture; it stays a silent no-op, matching this codebase's
        // no-magic style (see the trait's own doc block).
        $this->assertNull($this->app->make(CorrelationContext::class)->current());

        $job = new CorrelatedTestJob;

        $this->app->forgetScopedInstances();

        $job->handle();

        $this->assertTrue($job->handled);
        $this->assertNull($this->app->make(CorrelationContext::class)->current());
    }
}
