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
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
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

    private function draftIdAtStep2(): string
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
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
        $draftId = $this->draftIdAtStep2();
        $draft = BookingDraft::query()->findOrFail($draftId);
        $draft->forceFill([
            'cemetery_id' => $cemetery->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
            'completed_steps' => [
                BookingWizardStep::LOCATION,
                BookingWizardStep::CEMETERY,
                BookingWizardStep::SERVICE_TYPE,
                BookingWizardStep::SERVICES,
                BookingWizardStep::CUSTOMER_DATA,
                BookingWizardStep::DECEASED_DATA,
            ],
        ])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', 'BCA 123456789 a.n. Uji Coba')
            ->call('saveStep8', BookingPaymentMethod::MANUAL) // reaching this line at all proves no 500 / uncaught exception
            ->assertHasErrors('plot') // the honest "hold expired, pick again" copy, not a swallowed failure
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);
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
        $draftId = $this->draftIdAtStep2();
        $draft = BookingDraft::query()->findOrFail($draftId);
        $draft->forceFill([
            'cemetery_id' => $cemetery->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
            'completed_steps' => [
                BookingWizardStep::LOCATION,
                BookingWizardStep::CEMETERY,
                BookingWizardStep::SERVICE_TYPE,
                BookingWizardStep::SERVICES,
                BookingWizardStep::CUSTOMER_DATA,
                BookingWizardStep::DECEASED_DATA,
            ],
        ])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);
    }
}
