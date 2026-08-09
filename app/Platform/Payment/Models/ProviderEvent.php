<?php

declare(strict_types=1);

namespace App\Platform\Payment\Models;

use App\Platform\Payment\Exceptions\ProviderEventIsAppendOnlyException;
use App\Platform\Payment\ProviderEventStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for `provider_events` — see
 * `2026_08_09_100200_create_provider_events_table.php` for the schema and for
 * why the secondary unique guard is partial.
 *
 * ---------------------------------------------------------------------------
 * "Append-only" with a moving status is not a contradiction
 * ---------------------------------------------------------------------------
 * design.md §Data says `provider_events` is append-only. `payment-webhook.md`
 * §Failure states, in the same breath, defines a lifecycle the row moves
 * through (`RECEIVED → VALIDATED → PROCESSED`, or a terminal `REJECTED_*`).
 * Both are honoured by making the distinction explicit: the EVIDENCE is
 * immutable, the LIFECYCLE is not.
 *
 * `MUTABLE_COLUMNS` is the whole of the mutable surface. Everything else —
 * `raw_payload`, `payload_digest`, the identity columns, `merchant_ref`,
 * `amount_minor`, `received_at` — throws on change, and nothing may be
 * deleted. A webhook whose stored body could be edited after the fact is not
 * a replay source of truth.
 *
 * Same honesty caveat as `PaymentIntent` and `App\Platform\Audit\Models\AuditEvent`
 * (finding N-1: one Postgres role per environment owns the schema and runs the
 * application, so there is no lower-privileged role to REVOKE UPDATE/DELETE
 * from). These overrides stop `$event->update([...])`, `$event->save()` on a
 * persisted instance, and `$event->delete()`. They do NOT stop
 * `ProviderEvent::query()->update([...])`, `DB::table('provider_events')->…`,
 * raw SQL, or any process holding direct database credentials.
 *
 * ---------------------------------------------------------------------------
 * `raw_payload` never reaches a log, a queue payload, or an audit row
 * ---------------------------------------------------------------------------
 * AC14 and `AGENTS.md` §Observability. The cast keeps it encrypted at rest;
 * `$hidden` keeps it out of `toArray()`/`toJson()`, which is what most
 * accidental disclosure routes through (a `Log::info('…', $event->toArray())`,
 * an exception context, a serialized queue payload). `Jobs\ProcessProviderEventJob`
 * additionally takes only this row's id, never the model, so no payload is ever
 * serialized onto a queue.
 */
final class ProviderEvent extends Model
{
    use HasUuids;

    /**
     * The only columns that may change after a row is written — see the class
     * doc block.
     *
     * @var list<string>
     */
    public const array MUTABLE_COLUMNS = [
        'status',
        'rejection_detail',
        'validated_at',
        'signature_mechanism',
        'updated_at',
    ];

    protected $table = 'provider_events';

    /**
     * `id` is deliberately not fillable — `HasUuids` generates it. Same
     * reasoning as `PaymentIntent`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_id_source',
        'provider_transaction_id',
        'invoice_reference',
        'event_type',
        'merchant_ref',
        'amount_minor',
        'declared_currency',
        'event_occurred_at',
        'raw_payload',
        'payload_digest',
        'signature_mechanism',
        'status',
        'rejection_detail',
        'received_at',
        'validated_at',
        'correlation_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'raw_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `integer`, never `float`/`decimal` — Wave 0 ruling 0c. This is
            // the value AC6's amount check compares against, so an inexact
            // type here would be an inexact security check.
            'amount_minor' => 'integer',
            'raw_payload' => 'encrypted',
            'event_occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $event): void {
            // The status column is CHECK-constrained on Postgres; this makes
            // the same closed list real on SQLite (the test default) and fails
            // at the model rather than at the driver.
            if (! in_array($event->status, ProviderEventStatus::values(), true)) {
                throw ProviderEventIsAppendOnlyException::forOperation(
                    "status [{$event->status}] is not one of: ".implode(', ', ProviderEventStatus::values())
                );
            }
        });
    }

    public function status(): ProviderEventStatus
    {
        return ProviderEventStatus::from($this->status);
    }

    /**
     * The ONE way this row's lifecycle column moves. Keeping it here rather
     * than at call sites means the append-only guard below never has to guess
     * whether a caller meant to touch evidence.
     */
    public function markStatus(ProviderEventStatus $status, ?string $rejectionDetail = null): void
    {
        $this->status = $status->value;

        if ($rejectionDetail !== null) {
            // Bounded to the column width so a long internal detail can never
            // cause a write failure on the rejection path — the path whose
            // whole purpose is that a rejection is never silently lost (AC6).
            $this->rejection_detail = mb_substr($rejectionDetail, 0, 191);
        }

        if ($status === ProviderEventStatus::Validated) {
            $this->validated_at = now()->toImmutable();
        }

        $this->saveLifecycle();
    }

    /**
     * Always throws — mass update is not a lifecycle transition. Use
     * `markStatus()`.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ProviderEventIsAppendOnlyException::forOperation('update');
    }

    /**
     * Always throws — see the class-level doc block.
     */
    public function delete(): ?bool
    {
        throw ProviderEventIsAppendOnlyException::forOperation('delete');
    }

    /**
     * Blocks `$event->raw_payload = …; $event->save();` on an already-persisted
     * instance, which routes here rather than through `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        $changed = array_keys($this->getDirty());
        $immutable = array_values(array_diff($changed, self::MUTABLE_COLUMNS));

        if ($immutable !== []) {
            throw ProviderEventIsAppendOnlyException::forImmutableColumns($immutable);
        }

        return parent::performUpdate($query);
    }

    /**
     * `save()` for a lifecycle-only change. Deliberately private-by-convention
     * (only `markStatus()` calls it) so there is exactly one door.
     */
    private function saveLifecycle(): void
    {
        $this->save();
    }
}
