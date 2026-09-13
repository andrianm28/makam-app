<?php

declare(strict_types=1);

namespace App\Platform\Outbox\Jobs;

use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Dispatched by `App\Platform\Outbox\OutboxPublisher::dispatchOne()` onto
 * the queue `OutboxQueueRouter::routeFor()` names for the claimed row's
 * `event_name`. Builds the versioned envelope
 * (`outbox-event-contract.md`) and fires `OutboxEventPublished` with it —
 * see that event class's own doc block for exactly what "publish" does and
 * does not mean in this minimum implementation.
 *
 * Takes the outbox event's `id` (a plain string), not the model itself —
 * standard Laravel queue practice: re-fetch fresh state in `handle()`
 * rather than serializing a possibly-stale model instance onto the queue.
 *
 * ---------------------------------------------------------------------------
 * QUE-04: `dispatched_at` is stamped HERE, not by the dispatcher
 * ---------------------------------------------------------------------------
 * `OutboxPublisher::dispatchOne()` used to stamp `dispatched_at` immediately
 * after handing this job to the queue driver — before the job had run at
 * all. A job that was queued successfully but then failed every retry (a
 * bad payload, a listener exception, a crash-looping worker) left the row
 * permanently `dispatched_at IS NOT NULL` with no publish having actually
 * happened: invisible to `OutboxPublisher::claim()`'s reclaim query
 * (`dispatched_at IS NULL`), invisible to `SpineWatchdogCommand`'s stale-
 * outbox check (same predicate), and with no replay path. The event was
 * gone.
 *
 * The fix moves the stamp to the one place that knows the publish actually
 * happened: the end of a successful `handle()`. `locked_at` (set by
 * `claim()`, left alone by `dispatchOne()`) is the in-flight marker for the
 * whole window between "claimed" and "actually published" — cleared only
 * here on success, or by `failed()` below once retries are exhausted. A job
 * that never reaches either path (a hard worker crash mid-`handle()`) still
 * self-heals through `claim()`'s existing stale-claim reclaim
 * (`STALE_CLAIM_SECONDS`), same as any other stuck claim.
 */
final class PublishOutboxEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $outboxEventId,
    ) {}

    public function handle(): void
    {
        $row = OutboxEvent::query()->find($this->outboxEventId);

        if ($row === null) {
            // Claimed, then the row vanished before this job ran (nothing
            // in this codebase deletes outbox_events rows today, but
            // handle() must not assume that forever) — nothing to publish.
            return;
        }

        Event::dispatch(new OutboxEventPublished($this->buildEnvelope($row)));

        // Only reached once the event has actually been fired — see the
        // class doc block. `locked_at` is cleared here too: this job is the
        // only thing that knows the in-flight window is over.
        $row->forceFill([
            'dispatched_at' => CarbonImmutable::now(),
            'locked_at' => null,
        ])->save();
    }

    /**
     * Laravel's terminal-failure hook — runs once this job has exhausted
     * every retry the queue/worker configuration allows (Horizon's
     * per-supervisor `tries`, `config/horizon.php`). Without this hook, a
     * permanently-failed job left the row exactly as `dispatchOne()`
     * originally left it: `locked_at` set from `claim()`, `dispatched_at`
     * still null (with this fix) — reclaimable only once
     * `OutboxPublisher::STALE_CLAIM_SECONDS` (5 minutes) elapses, and with
     * no visible signal that this row failed rather than merely being
     * slow.
     *
     * This hook makes both problems visible immediately instead of waiting
     * on the stale-claim window: it clears `locked_at` (reclaimable on the
     * very next `outbox:publish` tick), records `last_error`, and advances
     * `attempt_count`/`available_at` using the SAME bounded-backoff formula
     * `OutboxPublisher::dispatchOne()`'s own catch block uses for a
     * dispatch-time failure — one formula, one place it is defined
     * (`OutboxPublisher::backoffSeconds()`), for both failure paths.
     */
    public function failed(Throwable $exception): void
    {
        $row = OutboxEvent::query()->find($this->outboxEventId);

        if ($row === null || $row->dispatched_at !== null) {
            // Already published (or the row is gone) — this failure came
            // from something downstream of a successful publish (a listener
            // throwing after the fact would already have been caught inside
            // handle()'s own Event::dispatch() call, but fail closed rather
            // than assume). Nothing to reclaim.
            return;
        }

        $attempt = $row->attempt_count + 1;

        $row->forceFill([
            'locked_at' => null,
            'attempt_count' => $attempt,
            'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            'available_at' => CarbonImmutable::now()->addSeconds(OutboxPublisher::backoffSeconds($attempt)),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEnvelope(OutboxEvent $row): array
    {
        return [
            'event_id' => $row->getKey(),
            'event_name' => $row->event_name,
            'event_version' => $row->event_version,
            'occurred_at' => $row->occurred_at?->toRfc3339String(),
            'trace_id' => $row->trace_id,
            'aggregate' => [
                'type' => $row->aggregate_type,
                'id' => $row->aggregate_id,
            ],
            // Known gap, documented at the point columns were decided:
            // 2026_07_26_140000_create_outbox_events_table.php's own doc
            // block. queue-and-outbox.md §5's minimum schema has no
            // actor_type/actor_id columns to source real values from, so
            // this key is present (envelope-shape conformance) but always
            // null in this minimum implementation.
            'actor' => [
                'type' => null,
                'id' => null,
            ],
            'classification' => $row->classification,
            'idempotency_key' => $row->idempotency_key,
            'data' => $row->payload,
        ];
    }
}
