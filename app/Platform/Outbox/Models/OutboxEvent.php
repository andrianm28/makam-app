<?php

declare(strict_types=1);

namespace App\Platform\Outbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Eloquent model for `outbox_events`
 * (`2026_07_26_140000_create_outbox_events_table.php`).
 *
 * ONLY ever INSERT a row here via `App\Platform\Outbox\Outbox::record()` —
 * never call `OutboxEvent::create()`/`::insert()` directly from application
 * code outside that class. `Outbox::record()` is where AC7's
 * `PayloadClassification` check and the `trace_id` sourcing both happen;
 * constructing a row any other way skips both silently. Same
 * "construct/call only through here" pattern as `App\Platform\Audit\Models\
 * AuditEvent` and `Audit::record()`.
 *
 * ---------------------------------------------------------------------------
 * Why this model does NOT copy `AuditEvent`'s append-only
 * `update()`/`performUpdate()`/`delete()` overrides
 * ---------------------------------------------------------------------------
 * `AuditEvent` throws on any post-insert mutation because an audit record
 * must never be revised after it is written — that IS its correctness
 * property. An outbox row has the opposite correctness property: it is
 * MEANT to be mutated after insert, repeatedly, by the claim/publish/retry
 * loop this table exists to support —
 * `locked_at`/`dispatched_at`/`attempt_count`/`last_error`/`available_at`
 * are legitimately written by `App\Platform\Outbox\OutboxPublisher` every
 * time it claims, dispatches, or retries a row. Copying `AuditEvent`'s
 * guard here would not add safety; it would make `OutboxPublisher` unable
 * to do its job. The two modules deliberately do NOT look alike on this
 * point — a future reader should not assume "audit" and "outbox" share a
 * mutability model just because they sit in sibling `Platform/*` modules
 * with a similar "one write API" discipline on the INSERT side.
 */
final class OutboxEvent extends Model
{
    /**
     * UUIDv7, not auto-increment. `id` is also `event_id` in the published
     * envelope (`outbox-event-contract.md`) — one identifier, not two.
     */
    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * No Eloquent `created_at`/`updated_at` pair — `occurred_at` already
     * carries "when," and this table's rows are mutated by design (see
     * class doc block above), so an `updated_at` column would be
     * legitimate here (unlike on `AuditEvent`) but is simply not part of
     * the reconciled minimum schema this batch built against
     * (`queue-and-outbox.md` §5's column list has no `updated_at` either).
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_name',
        'event_version',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'classification',
        'occurred_at',
        'available_at',
        'attempt_count',
        'locked_at',
        'dispatched_at',
        'last_error',
        'trace_id',
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $event) {
            $event->{$event->getKeyName()} ??= (string) Str::uuid7();
        });
    }
}
