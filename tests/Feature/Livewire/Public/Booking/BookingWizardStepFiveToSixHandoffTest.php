<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingRelationshipCode;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * This file used to cover the Step 5 (Ringkasan) dead-end regression: after
 * completing Step 4, a user landed on the read-only SUMMARY step with no
 * forward control into Step 6. Under the step reduction
 * (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`),
 * SUMMARY was CUT (not merged) and screens now converge 1:1 with steps, so
 * that whole bridge-step dead-end class of bug can no longer occur — there
 * is nothing left to strand a user on between DISCOVERY and
 * CUSTOMER_AND_DECEASED_DATA. The three regression tests that exercised it
 * were removed rather than migrated onto the new step numbering.
 *
 * This file now covers `BookingWizard::saveStep2()` — the merged
 * customer+deceased save that REPLACES the old `saveStep6()` (customer
 * data) / `saveStep7()` (deceased data) pair now that
 * `CUSTOMER_AND_DECEASED_DATA` is one step instead of two.
 */
final class BookingWizardStepFiveToSixHandoffTest extends TestCase
{
    use RefreshDatabase;

    private BookingDraft $draftAtDiscoveryComplete;

    private string $knownRelationshipCode = BookingRelationshipCode::ANAK;

    private string $knownContactChannel = BookingContactChannel::WHATSAPP;

    protected function setUp(): void
    {
        parent::setUp();

        $draft = (new StartBookingDraft)();

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $this->draftAtDiscoveryComplete = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
                ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
            ],
        ], 'idem-discovery');
    }

    public function test_save_step2_persists_customer_and_deceased_fields_together(): void
    {
        $component = Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtDiscoveryComplete->id])
            ->set('customerFullName', 'Budi Santoso')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'budi@example.test')
            ->set('customerAddress', 'Jl. Contoh No. 1, Jakarta')
            ->set('customerRelationship', $this->knownRelationshipCode)
            ->set('customerContactChannel', $this->knownContactChannel)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Almarhum Contoh')
            ->set('deceasedDateOfBirth', '1950-01-01')
            ->set('deceasedDateOfDeath', '2026-01-01')
            ->set('deceasedRelationship', $this->knownRelationshipCode)
            ->call('saveStep2');

        $component->assertHasNoErrors();

        $draft = $this->draftAtDiscoveryComplete->fresh();
        $this->assertSame('Budi Santoso', $draft->customer_full_name);
        $this->assertSame('Almarhum Contoh', $draft->deceased_full_name);
        $this->assertSame(BookingWizardStep::PAYMENT, $draft->current_step);
    }

    private function draftIdAtPayment(): string
    {
        return $this->advanceToCustomerAndDeceasedData($this->draftAtDiscoveryComplete)->id;
    }

    private function advanceToCustomerAndDeceasedData(BookingDraft $draft): BookingDraft
    {
        return (new SaveBookingDraftStep)($draft, BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, [
            'customer_full_name' => 'Budi Santoso',
            'customer_mobile' => '081234567890',
            'customer_email' => 'budi@example.test',
            'customer_address' => 'Jl. Contoh No. 1, Jakarta',
            'customer_relationship' => $this->knownRelationshipCode,
            'customer_contact_channel' => $this->knownContactChannel,
            'privacy_notice_accepted' => true,
            'deceased_full_name' => 'Almarhum Contoh',
            'deceased_date_of_birth' => '1950-01-01',
            'deceased_date_of_death' => '2026-01-01',
            'deceased_relationship' => $this->knownRelationshipCode,
        ], 'idem-customer-and-deceased-'.$draft->id);
    }

    private function discoveryCompleteDraft(): BookingDraft
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new StartBookingDraft)();

        return (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
                ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
            ],
        ], 'idem-discovery-'.$draft->id);
    }

    /**
     * `saveStep3()` is a pure rename of the old `saveStep8()` — same body,
     * same `BookingWizardStep::PAYMENT` target. This pins that the rename
     * did not silently change behaviour: the manual branch still submits
     * the draft as a real order and lands the journey on CONFIRMATION.
     */
    public function test_save_step3_persists_payment_and_advances_to_confirmation(): void
    {
        $draftId = $this->draftIdAtPayment();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', 'TRX-123456')
            ->call('saveStep3', BookingPaymentMethod::MANUAL);

        $component->assertHasNoErrors();
        $component->assertSet('currentStep', BookingWizardStep::CONFIRMATION);

        $draft = BookingDraft::query()->findOrFail($draftId);
        $this->assertSame(BookingPaymentMethod::MANUAL, $draft->payment_method);
        $this->assertSame(BookingWizardStep::CONFIRMATION, $draft->current_step);
    }

    /**
     * `currentScreen()` was simplified from a 4-branch `match` to a direct
     * pass-through now that screens and steps converge 1:1 (Decision 9).
     * `currentStep` is `#[Locked]`, so it cannot be forced to an arbitrary
     * value via `->set()` even in a test — each value here comes from a
     * draft genuinely AT that step, via the real save chain.
     */
    public function test_current_screen_equals_current_step_for_every_known_step(): void
    {
        // Four INDEPENDENT drafts, one per depth — not one draft read back
        // at different points, since each save mutates the same row and
        // would make an earlier assertion observe the later state.
        $freshDraft = (new StartBookingDraft)();
        $discoveryCompleteDraft = $this->discoveryCompleteDraft();
        $customerAndDeceasedCompleteDraft = $this->advanceToCustomerAndDeceasedData($this->discoveryCompleteDraft());
        $paymentCompleteDraft = (new SaveBookingDraftStep)(
            $this->advanceToCustomerAndDeceasedData($this->discoveryCompleteDraft()),
            BookingWizardStep::PAYMENT,
            ['payment_method' => BookingPaymentMethod::MANUAL, 'payment_reference' => 'TRX-999'],
            'idem-payment',
        );

        $this->assertSame(
            BookingWizardStep::DISCOVERY,
            Livewire::test(BookingWizard::class, ['draftId' => $freshDraft->id])->instance()->currentScreen(),
        );
        $this->assertSame(
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            Livewire::test(BookingWizard::class, ['draftId' => $discoveryCompleteDraft->id])->instance()->currentScreen(),
        );
        $this->assertSame(
            BookingWizardStep::PAYMENT,
            Livewire::test(BookingWizard::class, ['draftId' => $customerAndDeceasedCompleteDraft->id])->instance()->currentScreen(),
        );
        $this->assertSame(
            BookingWizardStep::CONFIRMATION,
            Livewire::test(BookingWizard::class, ['draftId' => $paymentCompleteDraft->id])->instance()->currentScreen(),
        );
    }

    /**
     * `canReachStep()`'s only remaining special case: CONFIRMATION is
     * read-only (never written to `completedSteps`), so it must still be
     * reachable via `goToStep()` once PAYMENT is done, even though
     * CONFIRMATION itself is never "completed".
     */
    public function test_confirmation_is_reachable_via_go_to_step_once_payment_is_completed(): void
    {
        $draftId = $this->draftIdAtPayment();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', 'TRX-654321')
            ->call('saveStep3', BookingPaymentMethod::MANUAL)
            ->call('goToStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA)
            ->call('goToStep', BookingWizardStep::CONFIRMATION)
            ->assertSet('currentStep', BookingWizardStep::CONFIRMATION);
    }
}
