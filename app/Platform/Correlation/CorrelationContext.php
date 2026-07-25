<?php

declare(strict_types=1);

namespace App\Platform\Correlation;

/**
 * Holds the current request's/job's `CorrelationId` — the single source
 * every consumer (an `Audit::record()` call site today; a future outbox
 * writer, queue job, provider client, or notification, per the batch brief)
 * reads instead of re-deriving or re-threading the id by hand.
 *
 * ---------------------------------------------------------------------------
 * `scoped()`, not `singleton()` — identical reasoning to
 * `ActorContextResolver` (see that class's doc block for the full
 * explanation, not repeated here)
 * ---------------------------------------------------------------------------
 * This repo runs no Octane, but DOES run Horizon queue workers — long-lived
 * PHP processes that boot the application once and then process many jobs
 * in it. A bare `singleton()` binding of this class would leak the first
 * job's (or first request's) correlation id into every later job/request
 * handled by the same worker process, which is exactly the cross
 * request/job leak this value exists to prevent, not cause. `scoped()`
 * bindings are Laravel's primitive for "reset between Octane requests AND
 * between Horizon jobs" (`Container::forgetScopedInstances()`), which is
 * why `CorrelationServiceProvider` binds this with `scoped()`.
 *
 * Deliberately a plain mutable holder (not itself immutable, unlike
 * `CorrelationId`) — `set()` is called once per request by
 * `AssignCorrelationId`, and again once per job by
 * `CarriesCorrelationId::restoreCorrelationContext()`, each time re-binding
 * this SAME scoped instance to that request's/job's id rather than
 * replacing the container binding itself.
 */
final class CorrelationContext
{
    private ?CorrelationId $current = null;

    public function set(CorrelationId $id): void
    {
        $this->current = $id;
    }

    public function current(): ?CorrelationId
    {
        return $this->current;
    }
}
