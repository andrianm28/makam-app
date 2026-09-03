<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardExpiredHoldOnSubmitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `openOnlinePayment()`'s submission chain is only reachable when
     * `G-PAY-01` is open — mirrors `BookingWizardOnlinePaymentTest::
     * withPaymentGate()` exactly, since a closed gate makes
     * `SaveBookingDraftStep::validatePayment()` reject `payment_method:
     * ONLINE` before the draft-hold conversion this test targets is ever
     * reached.
     */
    private function withPaymentGate(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));

        $this->assertSame(
            $open ? PaymentMode::Online : PaymentMode::ManualCoordination,
            app(ModeResolver::class)->paymentMode(),
            'The fixture gate must resolve as requested or these tests prove nothing.',
        );
    }

    /**
     * A real, bound draft with DISCOVERY complete. The cemetery/service
     * fields this creates are irrelevant to every test below — each one
     * immediately `forceFill()`s its own cemetery/plot fixture over the
     * top — this call exists only to create and bind the draft, since
     * `saveStep1()` rolls back its own `currentOrNewDraft()` insert on any
     * validation failure and so needs a genuinely valid DISCOVERY payload
     * to create anything at all.
     */
    private function draftIdAtDiscovery(): string
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, array_map(
                static fn (string $code): array => ['code' => $code, 'quantity' => 1],
                ServiceCode::BASIC_CODES,
            ))
            ->assertHasNoErrors()
            ->get('draftId');

        $this->assertIsString($draftId);

        return $draftId;
    }

    public function test_manual_submission_with_an_expired_hold_routes_back_to_step_2_not_a_500(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtDiscovery();
        $draft = BookingDraft::query()->findOrFail($draftId);
        $draft->forceFill([
            'cemetery_id' => $cemetery->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
            'completed_steps' => [
                BookingWizardStep::DISCOVERY,
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            ],
        ])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', 'BCA 123456789 a.n. Uji Coba')
            ->call('saveStep3', BookingPaymentMethod::MANUAL) // reaching this line at all proves no 500 / uncaught exception
            ->assertHasErrors('plot') // the honest "hold expired, pick again" copy, not a swallowed failure
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);
    }

    /**
     * The recovery state routing back to Step 2 is only a real requirement
     * if the customer cannot simply step around it. Before the screen
     * consolidation they could not: Step 2 was its own page, and Step 4's
     * "Lanjutkan" was not on it. Now steps 1-4 are ALL Screen 1 (`currentScreen()`
     * returns 1 for `CEMETERY`) and Step 4 is in `$completedSteps`, so its
     * forward control renders right below the plot picker — and
     * `continueFromStep4()` succeeds, because step 4's own data is still
     * perfectly valid. One click and the customer is on Screen 2, past the
     * re-pick, with the `plot` error and the whole point of routing back
     * silently discarded.
     *
     * This asserts the control is not offered while that error stands, and —
     * the half that makes it a real test rather than a snapshot — that it
     * comes back the moment a plot is genuinely re-held, so the fix cannot
     * be "hide the button forever". Screen 1 stays where the customer is
     * throughout.
     *
     * SCOPE, stated rather than implied: the gate is exactly as durable as
     * the error it reads. Livewire does not carry a component's error bag
     * across requests (verified against this component, not assumed), so
     * the error — and this gate with it — lasts for the render that
     * follows the failed submission: the render the customer is actually
     * looking at, and the only one from which the one-click bypass was
     * reachable. A customer who first clicks some OTHER control on Screen 1
     * clears the error and gets the forward control back. That residual
     * path is not a data-integrity hole: `saveStep8()`/`openOnlinePayment()`
     * re-check the hold server-side and route straight back here again (the
     * two tests either side of this one), so the booking still cannot be
     * submitted without a live hold.
     */
    public function test_an_expired_hold_recovery_does_not_offer_a_way_past_the_plot_re_pick(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtDiscovery();
        $draft = BookingDraft::query()->findOrFail($draftId);
        $draft->forceFill([
            'cemetery_id' => $cemetery->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
            'completed_steps' => [
                BookingWizardStep::DISCOVERY,
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            ],
        ])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', 'BCA 123456789 a.n. Uji Coba')
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertHasErrors('plot')
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);

        // DISCOVERY's services section is genuinely on screen — the forward
        // control being withheld is what is on trial, not the section being
        // absent.
        $this->assertSame(1, $component->instance()->currentScreen());
        $component->assertSee('Pilih Layanan');

        $component->assertDontSeeHtml('wire:click="continueFromDiscovery"')
            ->assertSee('Pilih plot terlebih dahulu pada bagian Pilih TPU/TPS di atas sebelum melanjutkan.');

        // Re-picking a plot is the way out, and it must actually be one:
        // once the hold is real again the forward control returns.
        $freshPlot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '002', 'plot_state' => 'available']);

        $component->call('holdPlotForDiscovery', $cemetery->id, null, $freshPlot->id)
            ->assertHasNoErrors('plot')
            ->assertSeeHtml('wire:click="continueFromDiscovery"');
    }

    public function test_online_submission_with_an_expired_hold_routes_back_to_step_2(): void
    {
        $this->withPaymentGate(true);

        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtDiscovery();
        $draft = BookingDraft::query()->findOrFail($draftId);
        $draft->forceFill([
            'cemetery_id' => $cemetery->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
            'completed_steps' => [
                BookingWizardStep::DISCOVERY,
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            ],
        ])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);
    }

    /**
     * The cemetery-mismatch case (2 Sep 2026, post-merge): a genuinely
     * still-`held` plot in cemetery A, but the draft finally saved cemetery
     * B — `selectCity()`'s own documented consequence of dropping the
     * cemetery under a city change while leaving the old hold to its TTL.
     * `SubmitBookingDraft` now throws for this too, the same as an expired
     * hold, and `routeBackToPlotPickerAfterExpiredHold()` releases the
     * mismatched hold as part of handling it.
     *
     * The release is the point of this test, not the routing (already
     * covered above for the expired case): without it, `activeForDraft()`
     * would still find the SAME stale hold on a retry, and the customer
     * would be stuck resubmitting into the same exception forever. Proven
     * two ways — the hold's own state, and that a genuine retry actually
     * creates the order.
     */
    public function test_a_hold_in_a_different_cemetery_is_released_so_a_retry_actually_succeeds(): void
    {
        $abandoned = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Ditinggalkan',
            'slug' => 'tpu-ditinggalkan-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
        $chosen = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Dipilih',
            'slug' => 'tpu-dipilih-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 2',
            // Aggregate-tier and no packages: the "no picker to re-pick in"
            // case the doc block on `holdBelongsToDraftCemetery()`
            // describes — the retry below must succeed on the ordinary
            // "no plot picked" path, not by re-holding anything in B.
            'plot_tracking_mode' => PlotTrackingMode::AGGREGATE,
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $abandoned->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);

        $draftId = $this->draftIdAtDiscovery();
        $draft = BookingDraft::query()->findOrFail($draftId);
        // The hold is taken against $abandoned's plot, but the draft finally
        // saves $chosen — exactly `selectCity()`'s documented divergence.
        $draft->forceFill([
            'cemetery_id' => $chosen->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
            'completed_steps' => [
                BookingWizardStep::DISCOVERY,
                BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            ],
        ])->saveQuietly();
        $held = app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', 'BCA 123456789 a.n. Uji Coba')
            ->call('saveStep3', BookingPaymentMethod::MANUAL) // reaching this line at all proves no 500
            ->assertHasErrors('plot')
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);

        $this->assertSame(0, Order::query()->count(), 'the first, mismatched attempt must not create an order');

        // The point of this test: the mismatched hold is genuinely released,
        // not left `held` to squat until its TTL. `PlotReservation` is
        // append-only (same discipline `SubmitBookingDraftConvertsPlotHoldTest`
        // documents for a converted hold) — the ORIGINAL row never mutates,
        // so `$held->fresh()->state` stays `held` forever by design. The
        // release is visible two other ways instead: a new `released` row is
        // appended for the same draft, and `activeForDraft()` — which only
        // ever considers `held`/`confirmed` the active states — no longer
        // finds anything for this draft.
        $this->assertSame(PlotReservationState::HELD, $held->fresh()->state, 'append-only: original row unchanged');
        $latestForDraft = PlotReservation::query()
            ->where('booking_draft_id', $draft->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($latestForDraft);
        $this->assertSame(PlotReservationState::RELEASED, $latestForDraft->state);
        $this->assertNull(PlotReservation::activeForDraft($draft->fresh()), 'the released row must not be findable as an active hold — this is what closes the retry loop');
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);

        // The real proof: a retry now succeeds, because `activeForDraft()`
        // no longer finds the released hold. Cemetery B is aggregate-tier
        // (no picker), so the customer's only recovery action is clicking
        // Lanjutkan again from where the wizard left them — resubmitting
        // the same payment reference, no new plot pick required.
        $component->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertHasNoErrors('plot');

        $this->assertSame(1, Order::query()->count(), 'the retry must actually create the order — the whole point of releasing the hold');
        $this->assertSame(
            2,
            PlotReservation::query()->count(),
            'append-only history for the abandoned hold: the original `held` row plus the `released` row — the retry must not create a THIRD reservation for a cemetery the order has no plot in',
        );
    }
}
