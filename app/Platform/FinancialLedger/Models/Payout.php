<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Models;

use App\Platform\FinancialLedger\Exceptions\InvalidPayoutException;
use App\Platform\FinancialLedger\PayoutMethod;
use App\Platform\FinancialLedger\PayoutState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that money left the business to a vendor: the amount, the proof
 * reference, the approver, and the journal batch that recorded the movement.
 *
 * Written only by `App\Platform\FinancialLedger\Actions\ManualPayout`, which
 * is the only place that knows the authorisation, re-authentication and proof
 * requirements this row is supposed to evidence. `$guarded = ['*']` follows
 * the module's other models, so no request payload can mass-assign an approver
 * or a proof reference onto it.
 *
 * Carries a proof REFERENCE, never proof content — see the migration's own doc
 * block. No bank detail, no account number, no customer identity is stored on
 * this row.
 */
final class Payout extends Model
{
    protected $table = 'payouts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    /**
     * @return BelongsTo<VendorPayable, $this>
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(VendorPayable::class, 'payable_id');
    }

    /**
     * The journal batch this payout posted, looked up by the business key the
     * payout row stores. Not a foreign key: `journal_batches` is append-only
     * and owned by `Journal`, and a FK from a mutable workflow table into it
     * would give this table a say in what the ledger may do.
     *
     * @return BelongsTo<JournalBatch, $this>
     */
    public function journalBatch(): BelongsTo
    {
        return $this->belongsTo(JournalBatch::class, 'journal_business_key', 'business_key');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $payout): void {
            PayoutMethod::assertKnown((string) $payout->method);
            PayoutState::assertKnown((string) $payout->state);

            if ((int) $payout->amount_minor <= 0) {
                throw InvalidPayoutException::forNonPositiveAmount((int) $payout->amount_minor);
            }
        });
    }
}
