<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Models;

use App\Platform\FinancialLedger\Exceptions\InvalidVendorPayableException;
use App\Platform\FinancialLedger\VendorPayableState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A vendor obligation and its AC8 lifecycle position. Written only through
 * `App\Platform\FinancialLedger\Actions\VendorPayable` (assessment) and
 * `App\Platform\FinancialLedger\Actions\ManualPayout` (payout), which are the
 * two places that know the rules this row has to obey.
 *
 * `$guarded = ['*']` follows `JournalBatch`/`JournalEntry`: mass assignment is
 * closed so no controller, Livewire component or Filament Resource can set
 * `state` from request input. The Actions use `forceFill()` on the specific
 * columns they own.
 *
 * Unlike the journal models, this one is genuinely mutable — see the
 * migration's own doc block for why the append-only journal ruling stops at
 * the journal tables.
 */
final class VendorPayable extends Model
{
    protected $table = 'vendor_payables';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['*'];

    /**
     * The payout that discharged this payable, if one exists. `HasOne` and not
     * `HasMany` because `payouts.payable_id` carries a UNIQUE index — at most
     * one payout per payable is a database invariant, not a convention.
     *
     * @return HasOne<Payout, $this>
     */
    public function payout(): HasOne
    {
        return $this->hasOne(Payout::class, 'payable_id');
    }

    public function isHeld(): bool
    {
        return $this->state === VendorPayableState::HELD;
    }

    /**
     * We owe the vendor. Deliberately NOT "and therefore may be treated as
     * settled" — see `isPaidOut()`.
     */
    public function isPayable(): bool
    {
        return $this->state === VendorPayableState::PAYABLE;
    }

    /**
     * AC8's second half, expressed so that no caller has to re-derive it:
     * a payable is paid out only when it is BOTH in the `paid` state AND
     * backed by a `payouts` row. The state alone is not the answer — the plan
     * is explicit that "a payout is never 'implied completed' merely by having
     * been created", so the proof-and-approver record is part of the claim,
     * not decoration on it.
     *
     * A `payable` row returns `false` here, which is the whole point: payable
     * is not paid out.
     */
    public function isPaidOut(): bool
    {
        if ($this->state !== VendorPayableState::PAID) {
            return false;
        }

        if ($this->relationLoaded('payout')) {
            return $this->getRelation('payout') !== null;
        }

        return $this->payout()->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'eligible_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $payable): void {
            VendorPayableState::assertKnown((string) $payable->state);

            if ((int) $payable->amount_minor <= 0) {
                throw InvalidVendorPayableException::forNonPositiveAmount((int) $payable->amount_minor);
            }
        });
    }
}
