<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\Quotation\Exceptions\UnpricedBookingServiceException;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Platform\FinancialLedger\Money;
use InvalidArgumentException;
use OverflowException;

/**
 * Task 1 of `docs/superpowers/plans/2026-08-14-p0-booking-submission-chain.md`:
 * maps a `BookingDraft`'s `selected_services` (code + quantity) onto the
 * `lines` shape `Actions\IssueQuote` consumes. The plan's "small quote-line
 * mapper" — one service per line, `line_total_minor = unit_amount_minor *
 * quantity`, using the same `ServiceDefinition::findByCode()`
 * `->currentPriceVersion()` seam Step 5's summary already uses.
 *
 * ---------------------------------------------------------------------------
 * The `unit_amount` boundary — the shape `IssueQuote` pins
 * ---------------------------------------------------------------------------
 * Each line's `unit_amount` is the price version's `amount` column as the
 * model's `decimal:2` cast returns it (a plain numeric string with exactly
 * two fraction digits, e.g. `"350000.00"` — no thousands separators, no
 * currency symbol). The same value is converted to integer minor units HERE
 * via `Money::fromDecimal()`, so a malformed stored amount is rejected at
 * this seam rather than surfacing deeper in `IssueQuote`. `IssueQuote`
 * converts the decimal string to minor units again at issuance — that is its
 * documented "conversion happens exactly once" boundary — and re-computes
 * `line_total_minor` itself; this mapper's `line_total_minor` (also integer
 * arithmetic, overflow-guarded) is the honest same-value figure the chain
 * asserts against.
 *
 * ---------------------------------------------------------------------------
 * No fabricated price — fail loud, because this feeds a write
 * ---------------------------------------------------------------------------
 * `BookingDraftQuery::summary()` renders Step 5 and may SKIP an unpriceable
 * line (read path, degrades to "harga belum tersedia"). This mapper feeds a
 * financial WRITE: silently dropping a selected service would underquote an
 * order, so any selection that cannot be priced — an unknown code, a
 * definition with no current price version, or a malformed JSON entry —
 * throws instead. The wizard's own step-4 validation guarantees the well-
 * formed cases; these branches defend the hand-edited-JSON-column case.
 */
final readonly class ComposeQuoteLinesFromBookingDraft
{
    /**
     * @return list<array{code: string, quantity: int, unit_amount: string, line_total_minor: int}>
     */
    public function __invoke(BookingDraft $draft): array
    {
        $lines = [];

        foreach ($draft->selected_services as $index => $selection) {
            $code = $this->codeOf($selection, $index);
            $quantity = $this->quantityOf($selection, $index);

            $definition = ServiceDefinition::findByCode($code);

            if (! $definition instanceof ServiceDefinition) {
                throw UnpricedBookingServiceException::forUnknownCode($code);
            }

            $priceVersion = $definition->currentPriceVersion();

            if (! $priceVersion instanceof PriceVersion) {
                throw UnpricedBookingServiceException::forCode($code);
            }

            $unitAmountMinor = Money::fromDecimal((string) $priceVersion->amount);

            $lines[] = [
                'code' => $code,
                'quantity' => $quantity,
                'unit_amount' => (string) $priceVersion->amount,
                'line_total_minor' => $this->lineTotalMinor($unitAmountMinor, $quantity),
            ];
        }

        return $lines;
    }

    private function codeOf(mixed $selection, int $index): string
    {
        if (! is_array($selection)
            || ! isset($selection['code'])
            || ! is_string($selection['code'])
            || $selection['code'] === '') {
            throw new InvalidArgumentException(
                "selected_services entry [{$index}] must be an array carrying a non-blank string [code]."
            );
        }

        return $selection['code'];
    }

    private function quantityOf(mixed $selection, int $index): int
    {
        if (! is_array($selection)
            || ! isset($selection['quantity'])
            || ! is_int($selection['quantity'])
            || $selection['quantity'] < 1) {
            throw new InvalidArgumentException(
                "selected_services entry [{$index}] must carry a positive integer [quantity]."
            );
        }

        return $selection['quantity'];
    }

    /**
     * `unit_amount_minor * quantity`, with the same explicit overflow guard
     * `IssueQuote::lineTotalMinor()` uses — a maliciously large quantity
     * must not silently wrap into a float.
     */
    private function lineTotalMinor(int $unitAmountMinor, int $quantity): int
    {
        if ($unitAmountMinor > intdiv(PHP_INT_MAX, $quantity)
            || $unitAmountMinor < intdiv(PHP_INT_MIN, $quantity)) {
            throw new OverflowException('Quote line total exceeds the integer range.');
        }

        return $unitAmountMinor * $quantity;
    }
}
