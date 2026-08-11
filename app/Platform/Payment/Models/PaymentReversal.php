<?php

declare(strict_types=1);

namespace App\Platform\Payment\Models;

use App\Platform\Payment\PaymentReversalType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Eloquent model for `payment_reversals` — see
 * `2026_08_11_100010_create_payment_reversals_table.php` for the schema
 * and for why this table has no foreign key to `payment_sessions`, any
 * order/booking table, or even `payment_verifications`.
 *
 * ---------------------------------------------------------------------------
 * One door, no others — `createRecorded()` is the only path that writes a
 * row
 * ---------------------------------------------------------------------------
 * Unlike `PaymentVerification` (which has three write methods across a
 * submit → decide workflow), a `payment_reversals` row is single-step:
 * recorded once, authorized once, no separate submit/decide phases — there
 * is no multi-party workflow to model without a real financial effect
 * behind it. `status`-style columns are unnecessary and deliberately
 * absent: a recorded reversal is terminal on creation.
 *
 * `reversal_type` and `recorded_at`/`recorded_by_actor_ref` are excluded
 * from `$fillable`, so `PaymentReversal::create([...])` or a plain
 * `$reversal->fill([...])` cannot set them — a caller that tries lands on
 * the `saving` hook's "unknown reversal_type" refusal below, because
 * `reversal_type` was never actually assigned.
 *
 * `App\Platform\Payment\Actions\RecordRefund` and
 * `App\Platform\Payment\Actions\RecordChargeback` (via
 * `App\Platform\Payment\ReversalService`) are the only callers of
 * `createRecorded()`.
 */
final class PaymentReversal extends Model
{
    use HasUuids;

    protected $table = 'payment_reversals';

    protected $keyType = 'string';

    /**
     * `id`, `reversal_type`, `recorded_at`, and `recorded_by_actor_ref` are
     * deliberately NOT fillable — see class doc block. `HasUuids` generates
     * `id`.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'amount_minor',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $reversal): void {
            // The reversal_type column is CHECK-constrained on Postgres;
            // this makes the same closed list real on SQLite (the test
            // default) and fails at the model rather than at the driver.
            // Also the structural guard against `::create()`/`fill()`
            // bypassing `createRecorded()` — see class doc block.
            if (! in_array($reversal->reversal_type, PaymentReversalType::values(), true)) {
                throw new LogicException(
                    'A payment_reversals row must have a known reversal_type before it can be saved; '
                    .'use PaymentReversal::createRecorded() rather than create()/fill().'
                );
            }
        });
    }

    /**
     * The only path that may write a new row. A recorded reversal is
     * terminal on creation — there is no follow-up `decide()`-style call.
     *
     * The caller (`RecordRefund`/`RecordChargeback`) is responsible for
     * catching the `(reversal_type, reference)` unique-constraint
     * violation and translating it into
     * `Exceptions\PaymentReversalAlreadyRecordedException` — this method
     * does not catch it itself, mirroring
     * `Actions\UploadDocument::createNew()`'s own division of
     * responsibility (the model writes; the Action translates the
     * database-level failure into a domain exception).
     *
     * @param  array<string, mixed>  $attributes  `reversal_type`, `reference`,
     *                                            `amount_minor`, `reason`, `recorded_by_actor_ref`, `recorded_at`.
     */
    public static function createRecorded(array $attributes): static
    {
        $reversal = new self;
        $reversal->fill($attributes);
        $reversal->forceFill([
            'reversal_type' => $attributes['reversal_type'],
            'recorded_by_actor_ref' => $attributes['recorded_by_actor_ref'] ?? null,
            'recorded_at' => $attributes['recorded_at'] ?? now(),
        ]);
        $reversal->save();

        return $reversal;
    }

    public function reversalType(): PaymentReversalType
    {
        return PaymentReversalType::from($this->reversal_type);
    }
}
