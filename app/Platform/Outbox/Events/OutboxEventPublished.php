<?php

declare(strict_types=1);

namespace App\Platform\Outbox\Events;

/**
 * Fired by `App\Platform\Outbox\Jobs\PublishOutboxEventJob::handle()` once
 * per outbox row it publishes. Carries the full versioned envelope
 * (`outbox-event-contract.md` shape) as a plain array.
 *
 * ---------------------------------------------------------------------------
 * What "publish" means in THIS minimum implementation — read before
 * assuming more
 * ---------------------------------------------------------------------------
 * "Publish" here means "fire an in-process Laravel event carrying the
 * envelope." There is no real external message bus/broker adapter, and no
 * real consumer (listener) for any of these envelopes exists anywhere in
 * this repo yet — no domain module currently registers an
 * `OutboxEventPublished` listener. That consumer wiring, along with
 * Horizon supervisors and bounded replay, is explicitly Sprint 6 scope per
 * `docs/planning/agent-execution-plan.md`. This class exists so
 * `OutboxPublisher`'s claim/dispatch loop has a real, observable, testable
 * side effect to prove against (see
 * `tests/Feature/Outbox/OutboxRecoveryTest.php`) without inventing a
 * consumer this batch has no authority to design.
 */
final class OutboxEventPublished
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public readonly array $envelope,
    ) {}
}
