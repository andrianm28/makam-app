<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Models\OutboxEvent;
use Carbon\CarbonImmutable;

/**
 * The ONE write API for `outbox_events` — mirrors
 * `App\Platform\Audit\Audit::record()`'s role for `audit_events`: AC1
 * ("insert it into the outbox in the same database transaction as the
 * state mutation") is a discipline this class enables but cannot itself
 * enforce end to end — see the transaction note below.
 *
 * ---------------------------------------------------------------------------
 * Does NOT open its own transaction — identical discipline to
 * `Audit::record()`
 * ---------------------------------------------------------------------------
 * Call `Outbox::record()` from INSIDE an existing `DB::transaction()` that
 * also performs the state mutation, to get AC1's guarantee. Called on its
 * own outside any transaction, this still writes a single row atomically (a
 * lone `INSERT` already is atomic) — it is simply not paired with any other
 * statement's commit/rollback. See
 * `tests/Feature/Outbox/OutboxTransactionTest.php` for the proof.
 *
 * No `Outbox::wrap()` counterpart to `Audit::wrap()` is provided.
 * `Audit::wrap()` earns its existence because EVERY audited mutation in
 * this codebase needs the identical "run this closure, then write one
 * audit row" shape. An outbox write's shape varies more per caller (event
 * name, version, aggregate reference, classification, optional idempotency
 * key all differ every time, and a single mutation may need to write more
 * than one outbox row) — a `wrap()` here would save one `DB::transaction()`
 * call at the cost of a second parallel API surface for no shared benefit
 * yet. Add one later if a real consuming spec shows the same repeated
 * shape `Audit::wrap()` was built for.
 *
 * ---------------------------------------------------------------------------
 * `trace_id` — the first real consumer of S3-T10's prepared mechanism
 * ---------------------------------------------------------------------------
 * Unlike `Audit::record()` (which takes `$correlationId` as a caller-
 * supplied, optional parameter — S3-T10 postdates it), this class reads
 * `app(CorrelationContext::class)->current()?->value` itself, directly,
 * at the point of insert. Finding N-10 (`docs/planning/sprint-plan.md`)
 * flagged the correlation mechanism as "prepared, not proven end-to-end"
 * because no real consumer existed yet — this is that consumer. It is
 * still not a full end-to-end proof (see this batch's report for exactly
 * what tests do and do not exercise: request boundary -> outbox insert is
 * covered; outbox -> queue job -> provider/notification propagation is
 * not, because no provider/notification classes exist in this repo yet).
 */
final class Outbox
{
    /**
     * Write exactly one `outbox_events` row.
     *
     * @param  string  $eventName  From `event-catalog.md` — this class does
     *                             not validate against the catalogue (AC3: do not restate it).
     * @param  int  $eventVersion  The envelope's `event_version`.
     * @param  string  $aggregateType  AC2: a reference to the aggregate this
     *                                 event is about — never its content.
     * @param  int|string  $aggregateId  The aggregate's own identifier.
     * @param  array<string, mixed>  $data  The envelope's `data` field.
     *                                      Checked by `PayloadClassification::assertSafe()` (AC7) — throws
     *                                      `OutboxPayloadKeyNotAllowedException` on a denylisted key at any
     *                                      nesting depth.
     * @param  OutboxClassification  $classification  Caller-declared — see
     *                                                `OutboxClassification`'s own doc block for why this class trusts
     *                                                it rather than re-deriving it.
     * @param  string|null  $idempotencyKey  Domain-unique key when the
     *                                       producer has one; must be unique across the table when non-null
     *                                       (database `UNIQUE` constraint — an `INSERT` with a colliding key
     *                                       throws a database-layer exception, not one defined here).
     */
    public static function record(
        string $eventName,
        int $eventVersion,
        string $aggregateType,
        int|string $aggregateId,
        array $data,
        OutboxClassification $classification,
        ?string $idempotencyKey = null,
    ): OutboxEvent {
        PayloadClassification::assertSafe($data);

        return OutboxEvent::create([
            'event_name' => $eventName,
            'event_version' => $eventVersion,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'payload' => $data,
            'classification' => $classification->value,
            'occurred_at' => CarbonImmutable::now(),
            'available_at' => CarbonImmutable::now(),
            'attempt_count' => 0,
            'trace_id' => app(CorrelationContext::class)->current()?->value,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
