<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\Quotation\Exceptions\UnpricedBookingPlotException;
use App\Domain\Quotation\Exceptions\UnpricedBookingServiceException;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Platform\FinancialLedger\Money;
use InvalidArgumentException;

/**
 * P0 submission chain — maps a `BookingDraft`'s `selected_services`
 * (code + quantity) onto the SERVICE-VERSION `lines` shape `Actions\
 * IssueQuote` consumes (Task 2 of
 * `docs/superpowers/plans/2026-08-14-p0-booking-submission-chain.md`,
 * reshaped per the 14 Aug ruling: the booking wizard quotes individual
 * SERVICES, so each line names a `ServiceDefinition` and its frozen
 * `PriceVersion` — never a synthesized package version).
 *
 * ---------------------------------------------------------------------------
 * The line shape — the ruling's service-version contract
 * ---------------------------------------------------------------------------
 * Each returned line is exactly
 * `array{service_definition_id: int, price_version_id: int,
 * price_version_number: int, quantity: int, unit_amount: string,
 * currency: string, fulfillment_owner: string}` — the keys `IssueQuote`'s
 * service-line branch requires. `service_definition_id` is resolved from
 * the selected code via `ServiceDefinition::findByCode()`, and
 * `price_version_id`/`price_version_number`/`unit_amount`/`currency` are
 * taken from `ServiceDefinition::currentPriceVersion()` — the same seam
 * Step 5's summary prices with, so the amount snapshot is exactly what
 * the customer saw. `fulfillment_owner` is the definition's own catalogue
 * declaration. `IssueQuote` re-computes `line_total_minor` at issuance;
 * this mapper ships no totals.
 *
 * ---------------------------------------------------------------------------
 * No fabricated price — fail loud, because this feeds a write
 * ---------------------------------------------------------------------------
 * `BookingDraftQuery::summary()` renders Step 5 and may SKIP an unpriceable
 * line (read path, degrades to "harga belum tersedia"). This mapper feeds a
 * financial WRITE: silently dropping a selected service would underquote an
 * order, so any selection that cannot be priced — an unknown code, a
 * definition with no current price version, or a malformed JSON entry —
 * throws instead (`UnpricedBookingServiceException`). The wizard's own
 * step-4 validation guarantees the well-formed cases; these branches
 * defend the hand-edited-JSON-column case.
 */
final readonly class ComposeQuoteLinesFromBookingDraft
{
    /**
     * @return list<array{service_definition_id: int, price_version_id: int, price_version_number: int, quantity: int, unit_amount: string, currency: string, fulfillment_owner: string}>
     */
    public function __invoke(BookingDraft $draft): array
    {
        // The plot comes FIRST: it is the largest component of the order, and
        // a reader scanning a quote should meet what was bought before what
        // was added to it.
        $lines = $this->plotLines($draft);

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

            $fulfillmentOwner = $definition->fulfillment_owner;

            if (! is_string($fulfillmentOwner) || $fulfillmentOwner === '') {
                throw new InvalidArgumentException(
                    "Selected service [{$code}] carries no fulfilment owner in the service catalog; ".
                    'refusing to compose a quote line without one.'
                );
            }

            // `unit_amount` is validated as an exact decimal-2 string right
            // here so a malformed stored amount is rejected at this seam
            // rather than surfacing deeper in `IssueQuote`.
            Money::fromDecimal((string) $priceVersion->amount);

            $lines[] = [
                'service_definition_id' => (int) $definition->getKey(),
                'price_version_id' => (int) $priceVersion->getKey(),
                'price_version_number' => (int) $priceVersion->version_number,
                'quantity' => $quantity,
                'unit_amount' => (string) $priceVersion->amount,
                'currency' => (string) $priceVersion->currency,
                'fulfillment_owner' => $fulfillmentOwner,
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
     * Zero or one plot line, from the plot this draft's customer chose.
     *
     * `PlotReservation::chosenForDraft()` returns at most one row per draft,
     * so no quantity question arises: a plot line is always quantity 1.
     *
     * It is `chosenForDraft()` and NOT `activeForDraft()` because every
     * caller of this mapper runs after `SubmitBookingDraft`, which converts
     * the draft hold to an order-anchored one. `activeForDraft()` excludes
     * `converted`, correctly for its own question and fatally for this one:
     * read here it returned null on every real submission and the plot — the
     * largest component of the order — silently left the quote.
     *
     * The pricing vehicle is the DRAFT's package. `grave_plots.cemetery_package_id`
     * is deliberately NOT read — its own migration calls it "an indicative
     * convenience reference, not the plot's identity", and it is
     * `nullOnDelete`, so a charge must not rest on it (spec D3).
     *
     * @return list<array<string, mixed>>
     */
    private function plotLines(BookingDraft $draft): array
    {
        $hold = PlotReservation::chosenForDraft($draft);

        if ($hold === null) {
            return [];
        }

        $packageId = $draft->cemetery_package_id;

        if ($packageId === null) {
            throw UnpricedBookingPlotException::forMissingPackage();
        }

        $package = CemeteryPackage::query()->find($packageId);

        if (! $package instanceof CemeteryPackage) {
            throw UnpricedBookingPlotException::forUnpricedPackage($packageId);
        }

        $plot = GravePlot::query()->with('block')->find($hold->plot_id);

        // TWO branches, not one compound condition. A missing plot is not a
        // cross-cemetery package, and collapsing them would make the thrown
        // message say "package [X] belongs to a different cemetery" when the
        // truth is that the held plot does not exist — a message that actively
        // misleads whoever reads it next.
        //
        // Splitting them also makes each clause pinnable: a single
        // `A || B` throwing one exception lets a test for B pass while A is
        // deleted, which is exactly the gap Task 3's review found in
        // `normalizePlotLine()`'s polymorphic check.
        if ($plot === null) {
            throw UnpricedBookingPlotException::forMissingPlot($hold->plot_id);
        }

        if ((string) $package->cemetery_id !== (string) $plot->block?->cemetery_id) {
            throw UnpricedBookingPlotException::forCrossCemeteryPackage($packageId);
        }

        $priceVersion = $package->currentPriceVersion();

        if (! $priceVersion instanceof PriceVersion) {
            throw UnpricedBookingPlotException::forUnpricedPackage($packageId);
        }

        // Same seam as the service branch: a malformed stored amount is
        // rejected here rather than deeper inside `IssueQuote`.
        Money::fromDecimal((string) $priceVersion->amount);

        return [[
            'grave_plot_id' => (string) $plot->getKey(),
            'cemetery_package_id' => (int) $package->getKey(),
            'price_version_id' => (int) $priceVersion->getKey(),
            'price_version_number' => (int) $priceVersion->version_number,
            'quantity' => 1,
            'unit_amount' => (string) $priceVersion->amount,
            'currency' => (string) $priceVersion->currency,
            'fulfillment_owner' => FulfillmentOwner::PLATFORM,
        ]];
    }
}
