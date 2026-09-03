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
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end coverage for the CUSTOMER_AND_DECEASED_DATA/PAYMENT/CONFIRMATION
 * handoffs. DISCOVERY is already covered by `BookingWizardStepFiveToSixHandoffTest`
 * and `BookingWizardStepsFourAndFiveTest`; this extends the journey through
 * the customer/deceased/payment/confirmation steps with valid payloads so a
 * regression in any hand-off is caught by the suite, not by a live user on
 * dev.
 */
final class BookingWizardStepsSixToNineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function componentAtCustomerAndDeceasedData(): Testable
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

        return Livewire::test(BookingWizard::class, ['draftId' => $draft->id]);
    }

    public function test_discovery_hands_off_to_customer_and_deceased_data_and_the_full_form_is_available(): void
    {
        $c = $this->componentAtCustomerAndDeceasedData()
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->assertSee('Langkah 2')
            ->assertSee('Nama Lengkap')
            ->assertSeeHtml('wire:submit="saveStep2"');
    }

    public function test_completing_customer_and_deceased_data_advances_to_payment(): void
    {
        $c = $this->componentAtCustomerAndDeceasedData()
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
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);
    }

    public function test_completing_payment_advances_to_confirmation(): void
    {
        $c = $this->componentAtCustomerAndDeceasedData()
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
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION);
    }

    /**
     * The manual path is the ONLY payment method live on production while
     * `G-PAY-01` stays closed — before this, `saveStep8()` saved the draft
     * step and stopped, so a manual submission never became a real `Order`
     * and was invisible to staff outside a direct database query. This
     * proves the gap is closed: submitting Step 8 with MANUAL creates a real
     * order at `MASUK`, linked to the draft, exactly like the online path's
     * `SubmitBookingDraft` call already does.
     */
    public function test_completing_payment_with_manual_payment_creates_a_real_order(): void
    {
        $c = $this->componentAtCustomerAndDeceasedData()
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

        $draftId = $c->get('draftId');

        $order = Order::query()->where('booking_draft_id', $draftId)->first();

        $this->assertNotNull($order, 'A manual submission must create a real order.');
        $this->assertSame(OrderStatus::MASUK->value, $order->status);

        $c->assertSee($order->reference);
    }

    /**
     * A double-click / retried request on the manual-payment button must
     * not create a second order — the same guarantee the online path
     * already relies on via `SubmitBookingDraft`'s idempotency key.
     */
    public function test_a_repeated_manual_payment_submission_does_not_duplicate_the_order(): void
    {
        $c = $this->componentAtCustomerAndDeceasedData()
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
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->call('saveStep3', BookingPaymentMethod::MANUAL);

        $draftId = $c->get('draftId');

        $this->assertSame(1, Order::query()->where('booking_draft_id', $draftId)->count());
    }
}
