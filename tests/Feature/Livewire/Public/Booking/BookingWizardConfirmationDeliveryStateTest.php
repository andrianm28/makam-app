<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\Notification\Contracts\Channel;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\Notification\FakeChannel;
use Tests\TestCase;

/**
 * NOTIF-09 (`docs/superpowers/plans/2026-09-07-batchm8b-notification-
 * completeness.md`): before this fix, Screen 4's "Pemberitahuan" card
 * hard-coded "Belum dikirim" for the email row regardless of whether a
 * real `notification_deliveries` row existed. This proves both halves: a
 * real delivery renders its real state, and a genuinely-not-yet-processed
 * order still falls back to the honest static pending badge.
 */
final class BookingWizardConfirmationDeliveryStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_delivery_row_renders_its_real_state_not_the_static_pending_badge(): void
    {
        $this->app->instance(Channel::class, new FakeChannel);
        $this->bindWhatsAppOpen();

        $c = $this->submitManualOrder();
        $order = Order::query()->where('booking_draft_id', $c->get('draftId'))->firstOrFail();

        // A real, mapped order-aggregate event — "Order processing" is the
        // one live production mapping this class's own subject-source doc
        // block names for `order.status_changed.v1`.
        $outboxEventId = Outbox::record(
            eventName: 'order.status_changed.v1',
            eventVersion: 1,
            aggregateType: 'order',
            aggregateId: (string) $order->id,
            data: ['order_id' => (string) $order->id],
            classification: OutboxClassification::Internal,
        )->getKey();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId, matrixEventName: 'Order processing');

        // Force a fresh render — `$c`'s last snapshot is from BEFORE the
        // delivery row above existed; `assertSee` otherwise checks that
        // stale HTML.
        $c->call('$refresh')
            ->assertSee('Email · Terkirim')
            ->assertDontSee('Belum dikirim');
    }

    public function test_no_delivery_row_yet_falls_back_to_the_static_pending_badge(): void
    {
        $this->bindWhatsAppOpen();

        $c = $this->submitManualOrder();

        $c->assertSee('Belum dikirim');
    }

    private function bindWhatsAppOpen(): void
    {
        $source = new class implements GateRegistrySource
        {
            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot(['G-WA-01' => GateState::fromRecord('G-WA-01', open: true)]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));
    }

    private function submitManualOrder(): Testable
    {
        $draft = (new StartBookingDraft)();

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
                ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
            ],
        ], 'idem-discovery-'.$draft->id);

        return Livewire::test(BookingWizard::class, ['draftId' => $draft->id])
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Almarhum Test')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep2')
            ->set('paymentReference', 'REF-001')
            ->call('saveStep3', BookingPaymentMethod::MANUAL);
    }
}
