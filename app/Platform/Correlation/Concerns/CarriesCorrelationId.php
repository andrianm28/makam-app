<?php

declare(strict_types=1);

namespace App\Platform\Correlation\Concerns;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;

/**
 * Opt-in mechanism for a future `ShouldQueue` job class to carry the
 * dispatching request's/job's correlation id across the process boundary
 * into the worker that eventually runs `handle()` —
 * `platform-outbox` requirements.md AC13: "propagate correlation id from
 * the request into the event and onward into queue jobs, provider calls,
 * and notifications."
 *
 * ---------------------------------------------------------------------------
 * Explicit and opt-in, NOT automatic — read this before using it
 * ---------------------------------------------------------------------------
 * This trait does nothing on its own. It relies on the adopting job class
 * calling both of its methods itself, at specific, explicit points:
 *
 *   1. `captureCorrelationContext()` — call this as (or from) the job's
 *      OWN constructor. Job construction/dispatch happens in the SAME
 *      process as the originating request (Horizon only serializes the
 *      job's public/protected state onto the queue after `dispatch()`
 *      returns), so this is a plain in-process read of whatever
 *      `CorrelationContext::current()` holds at construction time — not
 *      cross-process magic, and not guaranteed to see anything if called
 *      before `AssignCorrelationId` (or an equivalent job-side setter) has
 *      ever run. Only the id's plain string value is captured and stored
 *      on a private property, so it survives normal queue serialization
 *      (`CorrelationId` itself is not stored, to avoid coupling job
 *      payload serialization to that class's shape).
 *
 *   2. `restoreCorrelationContext()` — the job's OWN `handle()` method
 *      MUST call this explicitly, as its first line, once the job actually
 *      runs in the worker process. The worker process is a DIFFERENT PHP
 *      process (or, in a long-lived Horizon worker, the same process but a
 *      later job with `CorrelationContext` already flushed via
 *      `scoped()` — see `CorrelationContext`'s doc block) from the one
 *      that constructed the job, so the captured value has to be
 *      re-bound into that process's own (fresh) `CorrelationContext`
 *      rather than assumed to already be there.
 *
 * No magic base-class `handle()` wrapper, no service-provider hook that
 * fires this automatically on every job — matching this codebase's
 * established no-framework-magic style (see `ActorContextResolver`'s
 * `forget()` for the same "explicit escape hatch, not an automatic one"
 * pattern). A job author who forgets step 2 simply runs with no
 * correlation id bound, same as any job that never adopted this trait at
 * all — a safe, silent no-op, never a crash.
 *
 * ---------------------------------------------------------------------------
 * Honesty check for this batch's report — read before assuming this is
 * proven end-to-end
 * ---------------------------------------------------------------------------
 * As of this batch (S3-T10), ZERO real `ShouldQueue` job classes exist
 * anywhere in this repository — `app/Platform/Outbox/` is an empty
 * `.gitkeep` scaffold (S3-T11 builds the outbox publisher that will be the
 * first real consumer), and no other module has introduced a queued job
 * either. NO real job class adopts this trait today. It is proved in
 * isolation against a test-only fixture
 * (`tests/Fixtures/CorrelatedTestJob.php`) that manually simulates the
 * construct-then-later-restore sequence within one test process — that
 * proves the trait's OWN mechanism works, not a real end-to-end
 * dispatch-to-Horizon-worker path, since no real job and no running
 * Horizon worker exist in CI to prove that against. State this plainly
 * rather than implying more than is true.
 */
trait CarriesCorrelationId
{
    private ?string $capturedCorrelationId = null;

    /**
     * Call this from the adopting job's own constructor. Reads whatever
     * `CorrelationContext::current()` holds RIGHT NOW, in this (dispatching)
     * process — resolved fresh from the container at the point of use, not
     * cached anywhere before this call, so it reflects whatever the current
     * request/job actually bound.
     */
    protected function captureCorrelationContext(): void
    {
        $this->capturedCorrelationId = app(CorrelationContext::class)->current()?->value;
    }

    /**
     * Call this as the first line of the adopting job's own `handle()`.
     * Re-binds the captured id into THIS process's `CorrelationContext` —
     * a freshly `scoped()` instance, since `handle()` always runs in a
     * different scope (a new request/job boundary) than the constructor
     * that captured it. A no-op if nothing was captured (e.g.
     * `captureCorrelationContext()` was never called, or ran when no
     * correlation id was bound yet).
     */
    protected function restoreCorrelationContext(): void
    {
        if ($this->capturedCorrelationId === null) {
            return;
        }

        app(CorrelationContext::class)->set(
            CorrelationId::fromString($this->capturedCorrelationId)
        );
    }
}
