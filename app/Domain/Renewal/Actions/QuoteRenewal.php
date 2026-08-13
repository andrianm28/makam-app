<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\RenewalQuoteDraft;
use App\Platform\FinancialLedger\Money;

/**
 * Calculates a tariff quote for a grave renewal — AC6 (tariff amount + source
 * + effective time) and AC7 (no invented late fine).
 *
 * ---------------------------------------------------------------------------
 * This Action does NOT write. That is the whole point of it.
 * ---------------------------------------------------------------------------
 * It returns a `RenewalQuoteDraft` value and touches no table. Step 4 is an
 * anonymous, bookmarkable GET, and a GET that persists rows lets any visitor
 * — or any crawler — create `renewals` rows and claim the AC11 unique
 * business key for a grave they have no relationship to. `Actions\OpenRenewal`
 * is the only writer, and it is reached only from the family's explicit
 * acceptance of this quote.
 *
 * ---------------------------------------------------------------------------
 * Where the tariff comes from
 * ---------------------------------------------------------------------------
 * The quote is grounded in the cemetery's own price range (`price_min`,
 * `price_source`, `price_effective_at`) — the only attributed price data that
 * exists in this system for cemetery services. Per the coordinator's ruling
 * (2026-08-12 handoff): `price_min` → the amount, `price_source` → the tariff
 * source, `price_effective_at` → the effective time.
 *
 * **`price_min` is a `decimal:2` MAJOR-unit column and the quote is carried in
 * MINOR units.** It is therefore converted through `Money::fromDecimal()`,
 * never cast with `(int)`. An earlier revision used `(int) $cemetery->price_min`,
 * which silently truncated the decimal string `'4000000.00'` to the integer
 * `4000000` and then read that as minor units — quoting the family
 * **Rp 40.000 for a Rp 4.000.000 tariff**, a hundredfold understatement on the
 * one screen whose entire job is to state a price correctly. `Money::fromDecimal()`
 * is the codebase's documented conversion seam and it rejects, rather than
 * truncates, a value carrying more precision than the configured minor units.
 *
 * A quote with no attributable source is not a quote (the plan's own rule), so
 * a null `price_min` or a missing `price_source` throws rather than emit an
 * unattributed or unpriced figure.
 *
 * AC7's late-fine refusal is a gate read, not a calculation. `G-RATE-01`'s
 * documented closed behavior is literally "No invented fine." When no written
 * operator basis exists, the fine amount and its basis both stay null.
 */
final readonly class QuoteRenewal
{
    /**
     * @throws \InvalidArgumentException when the grave's cemetery has no
     *                                   attributable tariff source.
     */
    public function __invoke(GraveRecord $grave): RenewalQuoteDraft
    {
        $cemetery = $grave->cemetery;

        if (! $cemetery instanceof Cemetery) {
            throw new \InvalidArgumentException(
                'Cannot produce a renewal quote: grave has no associated cemetery.'
            );
        }

        if ($cemetery->price_min === null) {
            throw new \InvalidArgumentException(
                'Cannot produce a renewal quote: no attributable tariff source exists for this grave.'
            );
        }

        $tariffSource = trim((string) $cemetery->price_source);

        if ($tariffSource === '') {
            throw new \InvalidArgumentException(
                'Cannot produce a renewal quote: the cemetery price carries no source attribution.'
            );
        }

        // `renewal_quotes.tariff_effective_at` is NOT NULL (verified against
        // PostgreSQL 18). Without this check a cemetery priced but never dated
        // reaches the insert and fails as an unhandled integrity error rather
        // than the honest "no attributable tariff" refusal the screen renders.
        if ($cemetery->price_effective_at === null) {
            throw new \InvalidArgumentException(
                'Cannot produce a renewal quote: the cemetery price carries no effective time.'
            );
        }

        return new RenewalQuoteDraft(
            amountMinor: Money::fromDecimal((string) $cemetery->price_min),
            currency: 'IDR',
            tariffSource: $tariffSource,
            tariffEffectiveAt: $cemetery->price_effective_at,
            lateFineMinor: null,
            lateFineBasis: null,
        );
    }
}
