<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Quotation;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Exceptions\UnpricedBookingPlotException;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\ServiceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 4 of `docs/superpowers/plans/2026-09-17-plot-quote-lines.md` — the
 * composer's PLOT arm, ADR-0042.
 *
 * The fixture is built row by row (fresh cemetery/block/plot/package/price
 * per test) rather than through `Tests\Support\CemeteryFixture`'s seeded
 * roles — those roles carry no plots and no package price versions, and D3
 * (the pricing vehicle is the draft's package, checked against the held
 * plot's cemetery) needs full control over which cemetery each of the two
 * vehicles belongs to. Drafts are still built through the wizard's own
 * write path (`StartBookingDraft` + `SaveBookingDraftStep`, DISCOVERY step)
 * exactly as `ComposeQuoteLinesFromBookingDraftTest::draftWithSelectedServices()`
 * does — never a hand-rolled `BookingDraft::create([...])` bypassing that
 * validation — so the mapper is exercised against exactly the persisted
 * shape the wizard produces.
 */
final class ComposeQuoteLinesPlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_with_a_held_plot_emits_a_plot_line_first(): void
    {
        $draft = $this->draftWithHeldPlotAndPricedPackage();

        $lines = app(ComposeQuoteLinesFromBookingDraft::class)($draft);

        $this->assertArrayHasKey('grave_plot_id', $lines[0]);
        $this->assertArrayHasKey('cemetery_package_id', $lines[0]);
        $this->assertSame(1, $lines[0]['quantity']);
    }

    /**
     * C1 — the gap every other test in this file steps over.
     *
     * Each test above composes from a draft whose hold is still live, which
     * is a state no production call site ever sees: all three callers
     * (`IssueOrderQuote`, `OpenBookingOnlinePayment`, `QuotePreNeed`) run
     * AFTER `SubmitBookingDraft`, and submission runs
     * `ConvertDraftHoldToOrderReservation`, which closes the draft-scoped
     * chain with a `converted` row. `CONVERTED` is not in `ACTIVE_STATES`,
     * so `activeForDraft()` reads null and the plot silently leaves the
     * quote. This test is the one that composes on the far side of that
     * conversion.
     */
    public function test_a_submitted_draft_still_emits_its_plot_line(): void
    {
        $draft = $this->draftWithHeldPlotAndPricedPackage();

        $this->assertArrayHasKey(
            'grave_plot_id',
            app(ComposeQuoteLinesFromBookingDraft::class)($draft)[0],
            'Precondition: before submission the plot line is emitted.'
        );

        app(SubmitBookingDraft::class)($draft, 'idem-c1-'.Str::random(8));

        $lines = app(ComposeQuoteLinesFromBookingDraft::class)($draft->fresh());

        $this->assertArrayHasKey(
            'grave_plot_id',
            $lines[0],
            'The customer chose a plot and submitted it; the quote must still carry it.'
        );
    }

    /**
     * The pin that protects the OPERATOR path from this fix.
     *
     * `chosenForDraft()` is draft-scoped on purpose. The tempting shortcut —
     * reading `activeForOrder()` instead — also cures C1, and would newly
     * emit a plot line here, on an order whose plot an OPERATOR reserved
     * directly. `IssueQuoteFromReservedPlot` quotes exactly these orders
     * today and succeeds; under that shortcut it would throw
     * `UnpricedBookingPlotException` the moment the order's draft carried no
     * package.
     *
     * `ReservePlot` writes `order_id` with no `booking_draft_id`, so the
     * draft-scoped read cannot see it. Swap `chosenForDraft()` for
     * `activeForOrder()` and this test goes red.
     */
    public function test_an_operator_reserved_plot_is_not_quoted_onto_the_customers_draft(): void
    {
        $cemetery = $this->makeCemetery();
        $package = $this->makePackage($cemetery);
        $this->packagePriceVersion($package);

        // A draft that chose NO plot of its own, submitted to get an order.
        $draft = $this->draftWithSelectedServices($this->basicServices(), $cemetery, $package);
        $order = app(SubmitBookingDraft::class)($draft, 'idem-op-'.Str::random(8));

        // The operator reserves a plot against the ORDER, after the fact.
        app(ReservePlot::class)(
            $this->makePlot($cemetery),
            $order,
            'operator:1',
            'admin',
        );

        foreach (app(ComposeQuoteLinesFromBookingDraft::class)($draft->fresh()) as $line) {
            $this->assertArrayNotHasKey(
                'grave_plot_id',
                $line,
                'An operator reservation hangs off the order, not the draft; it must not become a customer quote line.'
            );
        }
    }

    /**
     * A withdrawn choice is not a choice. `released` and `expired` are the
     * two states `ACTIVE_OR_CONVERTED_STATES` deliberately excludes — widen
     * that constant to all of `KNOWN_STATES` and this test goes red.
     */
    public function test_a_released_plot_hold_emits_no_plot_line(): void
    {
        $cemetery = $this->makeCemetery();
        $plot = $this->makePlot($cemetery);
        $package = $this->makePackage($cemetery);
        $this->packagePriceVersion($package);

        $draft = $this->draftWithSelectedServices($this->basicServices(), $cemetery, $package);
        $this->holdPlotForDraft($plot, $draft);

        // `plot_reservations` is append-only, so a withdrawal is a NEW row
        // closing the chain — exactly how the real release path records it.
        PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'state' => PlotReservationState::RELEASED,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => Carbon::now(),
        ]);

        foreach (app(ComposeQuoteLinesFromBookingDraft::class)($draft) as $line) {
            $this->assertArrayNotHasKey('grave_plot_id', $line);
        }
    }

    public function test_a_draft_with_no_held_plot_emits_only_service_lines(): void
    {
        $draft = $this->draftWithServicesOnly();

        foreach (app(ComposeQuoteLinesFromBookingDraft::class)($draft) as $line) {
            $this->assertArrayNotHasKey('grave_plot_id', $line);
        }
    }

    public function test_a_held_plot_with_no_package_on_the_draft_is_refused(): void
    {
        $this->expectException(UnpricedBookingPlotException::class);

        app(ComposeQuoteLinesFromBookingDraft::class)($this->draftWithHeldPlotButNoPackage());
    }

    public function test_a_package_with_no_current_price_version_is_refused(): void
    {
        $this->expectException(UnpricedBookingPlotException::class);

        app(ComposeQuoteLinesFromBookingDraft::class)($this->draftWithHeldPlotAndUnpricedPackage());
    }

    public function test_a_package_from_another_cemetery_is_refused(): void
    {
        $this->expectException(UnpricedBookingPlotException::class);

        app(ComposeQuoteLinesFromBookingDraft::class)($this->draftWithCrossCemeteryPackage());
    }

    /**
     * The other half of what used to be one compound condition.
     *
     * Without this, deleting the `$plot === null` branch leaves every other
     * test green — the cross-cemetery test cannot reach it, because its plot
     * exists. Assert on the MESSAGE, not just the class: both branches throw
     * the same exception type, so the class alone cannot tell them apart.
     */
    public function test_a_hold_whose_plot_no_longer_exists_is_refused(): void
    {
        $this->expectException(UnpricedBookingPlotException::class);
        $this->expectExceptionMessageMatches('/no longer exists/');

        app(ComposeQuoteLinesFromBookingDraft::class)($this->draftWhoseHeldPlotWasDeleted());
    }

    // -------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------

    /**
     * A draft holding a plot whose cemetery's package carries a current
     * `PriceVersion` — the happy path.
     */
    private function draftWithHeldPlotAndPricedPackage(): BookingDraft
    {
        $cemetery = $this->makeCemetery();
        $plot = $this->makePlot($cemetery);
        $package = $this->makePackage($cemetery);
        $this->packagePriceVersion($package);

        $draft = $this->draftWithSelectedServices($this->basicServices(), $cemetery, $package);
        $this->holdPlotForDraft($plot, $draft);

        return $draft;
    }

    /**
     * A draft with real selected services and no plot hold at all — the
     * regression pin for "no hold, no plot line".
     */
    private function draftWithServicesOnly(): BookingDraft
    {
        $cemetery = $this->makeCemetery();
        $package = $this->makePackage($cemetery);
        $this->packagePriceVersion($package);

        return $this->draftWithSelectedServices($this->basicServices(), $cemetery, $package);
    }

    /**
     * A held plot, but the draft's `cemetery_package_id` is null. Built by
     * choosing a cemetery with NO ACTIVE package at all: `SaveBookingDraftStep`
     * only requires `cemetery_package_id` when the cemetery has active
     * packages to choose from, so a package-less cemetery legitimately
     * persists a null `cemetery_package_id` through the real wizard path.
     *
     * The plot's OWN `cemetery_package_id` is deliberately set to a real,
     * priced (but inactive, so unselectable) package — the "indicative
     * convenience reference" that must never substitute for the draft's
     * pricing vehicle. A plot with a null `cemetery_package_id` of its own
     * would make this fixture unable to distinguish "read the draft's
     * package" from "read the plot's package": Step 7's Mutation A needs a
     * real, wrong answer sitting right there to be wrongly read.
     */
    private function draftWithHeldPlotButNoPackage(): BookingDraft
    {
        $cemetery = $this->makeCemetery();

        $plotsOwnPackage = $this->makePackage($cemetery, isActive: false);
        $this->packagePriceVersion($plotsOwnPackage);

        $plot = $this->makePlot($cemetery, cemeteryPackageId: (int) $plotsOwnPackage->getKey());

        $draft = $this->draftWithSelectedServices($this->basicServices(), $cemetery, null);
        $this->holdPlotForDraft($plot, $draft);

        return $draft;
    }

    /**
     * A held plot whose cemetery's package exists and is active, but carries
     * no `PriceVersion` at all — the ordinary "not yet priced" state
     * `RecordCemeteryPackagePriceVersion`'s own doc block describes as true
     * of every package in the catalogue by default.
     */
    private function draftWithHeldPlotAndUnpricedPackage(): BookingDraft
    {
        $cemetery = $this->makeCemetery();
        $plot = $this->makePlot($cemetery);
        $package = $this->makePackage($cemetery);

        $draft = $this->draftWithSelectedServices($this->basicServices(), $cemetery, $package);
        $this->holdPlotForDraft($plot, $draft);

        return $draft;
    }

    /**
     * D3's whole point: the held plot sits in cemetery A, while the draft's
     * package belongs to cemetery B. A fixture where the two happen to
     * coincide could not distinguish "read the plot's own package" from
     * "read the draft's package" — exactly what Step 7's Mutation A
     * targets.
     */
    private function draftWithCrossCemeteryPackage(): BookingDraft
    {
        $cemeteryA = $this->makeCemetery();
        $plot = $this->makePlot($cemeteryA);

        $cemeteryB = $this->makeCemetery();
        $packageB = $this->makePackage($cemeteryB);
        $this->packagePriceVersion($packageB);

        $draft = $this->draftWithSelectedServices($this->basicServices(), $cemeteryB, $packageB);
        $this->holdPlotForDraft($plot, $draft);

        return $draft;
    }

    /**
     * A hold whose plot row no longer exists. `plot_reservations.plot_id`
     * carries a real `restrictOnDelete()` foreign key
     * (`2026_08_16_100020_create_plot_reservations_table.php`), so neither a
     * genuine `DELETE` nor an insert naming an unknown id can pass under
     * normal constraint enforcement. Disabling the table's triggers
     * (Postgres implements FK enforcement as internal triggers) for this one
     * write is the standard way this codebase constructs an otherwise-
     * impossible row state under a real FK — see
     * `ComplaintResolutionFlowTest::test_resolve_with_make_good_rolls_back_entirely_when_the_work_order_lookup_fails()`
     * for the identical pattern — and it is scoped to `RefreshDatabase`'s
     * per-test transaction, so it never persists past this test.
     */
    private function draftWhoseHeldPlotWasDeleted(): BookingDraft
    {
        $cemetery = $this->makeCemetery();
        $package = $this->makePackage($cemetery);
        $this->packagePriceVersion($package);

        $draft = $this->draftWithSelectedServices($this->basicServices(), $cemetery, $package);

        $missingPlotId = (string) Str::uuid();

        DB::statement('ALTER TABLE plot_reservations DISABLE TRIGGER ALL');
        PlotReservation::query()->create([
            'plot_id' => $missingPlotId,
            'booking_draft_id' => $draft->getKey(),
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);
        DB::statement('ALTER TABLE plot_reservations ENABLE TRIGGER ALL');

        return $draft;
    }

    // -------------------------------------------------------------------
    // Fixture builders
    // -------------------------------------------------------------------

    /**
     * Drives a real draft through the wizard's own DISCOVERY save — same
     * shape as `ComposeQuoteLinesFromBookingDraftTest::draftWithSelectedServices()`,
     * parameterised on the cemetery/package this suite's fixtures need to
     * control explicitly rather than resolving from a seeded role.
     *
     * @param  list<array{code: string, quantity: int}>  $services
     */
    private function draftWithSelectedServices(array $services, Cemetery $cemetery, ?CemeteryPackage $package): BookingDraft
    {
        $draft = (new StartBookingDraft)();

        return (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => $cemetery->city,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => $package?->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => $services,
        ], 'idem-discovery-'.Str::random(12));
    }

    private function holdPlotForDraft(GravePlot $plot, BookingDraft $draft): PlotReservation
    {
        return PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => Carbon::now(),
            'expires_at' => Carbon::now()->addMinutes(15),
        ]);
    }

    private function makeCemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(8)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
    }

    /**
     * `$cemeteryPackageId` seeds `grave_plots.cemetery_package_id` — the
     * "indicative convenience reference" ADR-0042 and this action's own doc
     * block say must NEVER be read as the pricing vehicle. Left null by
     * default; `draftWithHeldPlotButNoPackage()` is the one fixture that
     * sets it, so Mutation A (Step 7) has a real, differently-priced
     * package to reveal wrongly reading it instead of the draft's.
     */
    private function makePlot(Cemetery $cemetery, ?int $cemeteryPackageId = null): GravePlot
    {
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-'.Str::upper(Str::random(6)),
            'name' => 'Blok Uji',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => 'available',
            'cemetery_package_id' => $cemeteryPackageId,
        ]);
    }

    private function makePackage(Cemetery $cemetery, bool $isActive = true): CemeteryPackage
    {
        return CemeteryPackage::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'name' => 'Makam Tumpang',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
            'is_active' => $isActive,
        ]);
    }

    /**
     * `SaveBookingDraftStep::validateServices()` requires both
     * `ServiceCode::BASIC_CODES` to be present in `selected_services` — this
     * suite's fixtures care only about the plot line, so every draft carries
     * exactly the mandatory basics and nothing more.
     *
     * @return list<array{code: string, quantity: int}>
     */
    private function basicServices(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'quantity' => 1],
            ServiceCode::BASIC_CODES,
        );
    }

    private function packagePriceVersion(CemeteryPackage $package): PriceVersion
    {
        return PriceVersion::query()->create([
            'priceable_type' => CemeteryPackage::class,
            'priceable_id' => $package->getKey(),
            'version_number' => 1,
            'amount' => '75000000.00',
            'currency' => 'IDR',
            'source' => 'test fixture',
            'effective_from' => Carbon::now(),
            'recorded_by' => 'test',
        ]);
    }
}
