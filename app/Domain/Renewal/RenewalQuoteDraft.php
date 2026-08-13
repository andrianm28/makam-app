<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use App\Platform\FinancialLedger\Money;
use Carbon\CarbonImmutable;

/**
 * A tariff quote that has been CALCULATED but not persisted — AC6's amount,
 * source and effective time as a plain value, with no `renewals` row behind
 * it.
 *
 * ---------------------------------------------------------------------------
 * Why a quote must be expressible without a database row
 * ---------------------------------------------------------------------------
 * Step 4 (`Livewire\Public\Renewal\RenewalFee`) is a bookmarkable, anonymous
 * GET. An earlier revision of this lane had its `mount()` call an Action that
 * wrote a `renewals` row, which meant any unauthenticated GET — a crawler, a
 * refresh, a prefetch — created persistent rows and claimed the AC11 unique
 * business key `(grave_record_id, target_due_period)` for that grave. The
 * second such GET then collided with the constraint, and the collision also
 * blocked the admin AC10 external-marking path for the same grave and period.
 *
 * A GET must not write. This value object is what step 4 renders, so the
 * screen can show a real, fully-attributed tariff while `Actions\OpenRenewal`
 * stays the only writer, reached only from the family's explicit acceptance.
 *
 * `Actions\QuoteRenewal` produces one of these; `Actions\OpenRenewal` is the
 * only consumer that turns one into a persisted `Models\RenewalQuote`.
 */
final readonly class RenewalQuoteDraft
{
    /**
     * @param  int  $amountMinor  Integer minor units, already converted from the
     *                            cemetery's decimal price column through
     *                            `Money::fromDecimal()`. Never a raw major-unit
     *                            figure — see `Actions\QuoteRenewal` for why the
     *                            distinction is load-bearing.
     * @param  int|null  $lateFineMinor  Null whenever `G-RATE-01` is closed, which
     *                                   is AC7's "no invented fine" in its only
     *                                   current state.
     * @param  string|null  $lateFineBasis  The written operator basis for the fine.
     *                                      Null and `$lateFineMinor` null travel
     *                                      together; see `hasLateFine()`.
     */
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $tariffSource,
        public ?CarbonImmutable $tariffEffectiveAt,
        public ?int $lateFineMinor,
        public ?string $lateFineBasis,
    ) {}

    public function amountAsMoney(): Money
    {
        return new Money($this->amountMinor);
    }

    /**
     * A fine exists only when BOTH the figure and its written basis are
     * present. AC7 forbids an invented fine, and a figure without a basis is
     * exactly that; a basis without a figure has nothing to charge. Callers
     * render the fine block on this method, never on either field alone.
     */
    public function hasLateFine(): bool
    {
        return $this->lateFineMinor !== null && $this->lateFineBasis !== null;
    }

    public function lateFineAsMoney(): ?Money
    {
        return $this->hasLateFine() ? new Money((int) $this->lateFineMinor) : null;
    }
}
