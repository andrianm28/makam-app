<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * End-to-end coverage for the Steps 6-9 handoffs. Steps 1-5 are already
 * covered by BookingWizardEndToEndTest; this extends the journey through the
 * customer/deceased/payment/confirmation steps with valid payloads so a
 * regression in any hand-off after Step 5 (the dead-end that shipped) is
 * caught by the suite, not by a live user on dev.
 */
final class BookingWizardStepsSixToNineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function componentAtSummary(): Testable
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-a');

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id], 'idem-b');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'NEW_GRAVE'], 'idem-c');

        return Livewire::test(BookingWizard::class, ['draftId' => $draft->id]);
    }

    private function driveToSummary(Testable $c): Testable
    {
        return $c
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY);
    }

    public function test_step_5_hands_off_to_step_6_and_the_full_customer_form_is_available(): void
    {
        $c = $this->driveToSummary($this->componentAtSummary())
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_DATA)
            ->assertSee('Langkah 6')
            ->assertSee('Nama Lengkap')
            ->assertSeeHtml('wire:submit="saveStep6"');
    }

    public function test_completing_step_6_advances_to_step_7(): void
    {
        $c = $this->driveToSummary($this->componentAtSummary())
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->call('saveStep6')
            ->assertSet('currentStep', BookingWizardStep::DECEASED_DATA);
    }

    public function test_completing_step_7_advances_to_step_8_payment(): void
    {
        $c = $this->driveToSummary($this->componentAtSummary())
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->call('saveStep6')
            ->set('deceasedFullName', 'Almarhum Test')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep7')
            ->assertSet('currentStep', BookingWizardStep::PAYMENT);
    }

    public function test_completing_step_8_advances_to_step_9_confirmation(): void
    {
        $c = $this->driveToSummary($this->componentAtSummary())
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->call('saveStep6')
            ->set('deceasedFullName', 'Almarhum Test')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep7')
            ->set('paymentReference', 'REF-001')
            ->call('saveStep8', BookingPaymentMethod::MANUAL)
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
    public function test_completing_step_8_with_manual_payment_creates_a_real_order(): void
    {
        $c = $this->driveToSummary($this->componentAtSummary())
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->call('saveStep6')
            ->set('deceasedFullName', 'Almarhum Test')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep7')
            ->set('paymentReference', 'REF-001')
            ->call('saveStep8', BookingPaymentMethod::MANUAL);

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
        $c = $this->driveToSummary($this->componentAtSummary())
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->set('customerFullName', 'Test User')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'test@example.com')
            ->set('customerAddress', 'Jl. Contoh No. 1')
            ->set('customerRelationship', 'PASANGAN')
            ->set('customerContactChannel', 'WHATSAPP')
            ->set('privacyNoticeAccepted', true)
            ->call('saveStep6')
            ->set('deceasedFullName', 'Almarhum Test')
            ->set('deceasedDateOfBirth', '1980-05-10')
            ->set('deceasedDateOfDeath', '2026-08-01')
            ->set('deceasedRelationship', 'PASANGAN')
            ->set('deceasedGender', 'LAKI_LAKI')
            ->call('saveStep7')
            ->set('paymentReference', 'REF-001')
            ->call('saveStep8', BookingPaymentMethod::MANUAL)
            ->call('saveStep8', BookingPaymentMethod::MANUAL);

        $draftId = $c->get('draftId');

        $this->assertSame(1, Order::query()->where('booking_draft_id', $draftId)->count());
    }
}
