<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Models;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\RefundObligation\RefundObligationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A debt owed to a customer whose paid order was rejected — Stage R0 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * The plan's framing, kept here because it is what this class is for: *"utang
 * yang tidak dicatat adalah utang yang dilupakan"* — and the person forgotten
 * is a grieving family that has already paid.
 *
 * See the migration's doc block for why this is a new table rather than an
 * extension of `payment_reversals`, why `order_id` is unique, and why no
 * destination-of-funds column exists yet.
 *
 * ---------------------------------------------------------------------------
 * The status may only move forward, and only behind a recorded stamp
 * ---------------------------------------------------------------------------
 * `saving()` below refuses an illegal transition and refuses a status whose
 * timestamps do not support it. Postgres enforces the same two rules with
 * CHECK constraints; this makes them real on SQLite (the test default) and
 * fails at the model, where the message can say what to do instead, rather
 * than at the driver.
 *
 * Both layers matter. The database catches anything that reaches it by any
 * path; the model catches it before a transaction has been built around it.
 *
 * @property string $id
 * @property string $order_id
 * @property string|null $payment_session_id
 * @property int $amount_minor
 * @property string $currency
 * @property RefundObligationStatus $status
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable $opened_at
 * @property string|null $opened_by_actor_ref
 * @property string $opened_reason
 * @property CarbonImmutable|null $executed_at
 * @property string|null $executed_by_actor_ref
 * @property string|null $execution_reference
 * @property string|null $execution_evidence_path
 * @property CarbonImmutable|null $confirmed_at
 * @property string|null $confirmed_by_actor_ref
 */
final class RefundObligation extends Model
{
    use HasUuids;

    protected $table = 'refund_obligations';

    protected $keyType = 'string';

    /**
     * Deliberately narrow. Every column that carries a lifecycle decision —
     * `status` and the three stamp groups — is force-filled by an Action, so
     * no caller can advance an obligation by mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'payment_session_id',
        'amount_minor',
        'currency',
        'opened_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'status' => RefundObligationStatus::class,
            'due_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $obligation): void {
            // Read through `getAttribute()` rather than the typed property:
            // the point of this guard is a row that reached `save()` with no
            // status at all — `create()`/`fill()` bypassing the Actions — and
            // the `@property` annotation would make a check on the typed
            // accessor a tautology to static analysis.
            $status = $obligation->getAttribute('status');

            if (! $status instanceof RefundObligationStatus) {
                throw new LogicException(
                    'A refund_obligations row must carry a known status before it can be saved; '
                    .'use the Actions in App\Domain\RefundObligation\Actions rather than create()/fill().'
                );
            }

            if ($obligation->exists) {
                $original = $obligation->getOriginal('status');
                $previous = $original instanceof RefundObligationStatus
                    ? $original
                    : RefundObligationStatus::from((string) $original);

                if ($previous !== $status && ! $previous->canTransitionTo($status)) {
                    throw new LogicException(
                        "A refund obligation cannot move from {$previous->value} to {$status->value}. "
                        .'The status machine is forward-only, and nothing closes an obligation except a '
                        .'recorded execution with its evidence.'
                    );
                }
            }

            $obligation->assertStampsSupportStatus($status);
        });
    }

    /**
     * The mirror of the `refund_obligations_status_stamps_check` constraint.
     *
     * A status is a claim about what has happened to somebody's money. If the
     * stamp that would prove it is missing, the claim is false and the row
     * must not be written — an obligation that reads as paid with no evidence
     * behind it is precisely the failure this table exists to prevent.
     */
    private function assertStampsSupportStatus(RefundObligationStatus $status): void
    {
        $executed = $this->executed_at !== null;
        $confirmed = $this->confirmed_at !== null;

        $consistent = match ($status) {
            RefundObligationStatus::TERUTANG => ! $executed && ! $confirmed,
            RefundObligationStatus::DIEKSEKUSI => $executed && ! $confirmed,
            RefundObligationStatus::TERKONFIRMASI => $executed && $confirmed,
        };

        if (! $consistent) {
            throw new LogicException(
                "A refund obligation in status {$status->value} does not have the timestamps that status "
                .'claims. Status is a cache of executed_at/confirmed_at, never a substitute for them.'
            );
        }
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Obligations where money is still owed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function outstanding(Builder $query): void
    {
        $query->where('status', RefundObligationStatus::TERUTANG->value);
    }

    /**
     * Outstanding obligations whose deadline has passed — Stage R4's query.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query, ?CarbonImmutable $asOf = null): void
    {
        $query->outstanding()->where('due_at', '<', $asOf ?? CarbonImmutable::now());
    }

    public function isOverdue(?CarbonImmutable $asOf = null): bool
    {
        return $this->status->isOutstanding()
            && $this->due_at->lessThan($asOf ?? CarbonImmutable::now());
    }
}
