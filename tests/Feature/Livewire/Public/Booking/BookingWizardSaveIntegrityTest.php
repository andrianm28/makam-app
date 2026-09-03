<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingContactChannel;
use App\Domain\Booking\BookingRelationshipCode;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The save contract as seen FROM THE UI: idempotency, optimistic versioning,
 * unskippable steps, and honest feedback when a save cannot happen.
 *
 * `SaveBookingDraftStepIdempotencyTest` proves the Action's own contract with
 * explicit hand-written keys. This file proves the component actually
 * exercises it — the gap that let every real save arrive with a fresh random
 * key (so the replay branch was unreachable) and no `expectedVersion` (so no
 * conflict was ever detected) while the Action-level tests stayed green.
 */
final class BookingWizardSaveIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function jakartaCemeteryWithoutPackages(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    /**
     * @return list<array{code: string, quantity: int}>
     */
    private function basicServicesPayload(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'quantity' => 1],
            ServiceCode::BASIC_CODES,
        );
    }

    /**
     * A draft with DISCOVERY complete — one `saveStep1()` call now covers
     * what used to be steps 1-4, so this is the new "at step 2" fixture:
     * the draft lands directly on CUSTOMER_AND_DECEASED_DATA.
     */
    private function draftAtCustomerAndDeceasedData(): string
    {
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, $this->basicServicesPayload())
            ->assertHasNoErrors()
            ->get('draftId');

        $this->assertIsString($draftId);

        return $draftId;
    }

    // =====================================================================
    // Idempotency — the key is derived, not random
    // =====================================================================

    public function test_repeating_the_identical_save_is_a_no_op_rather_than_a_second_write(): void
    {
        $draftId = $this->draftAtCustomerAndDeceasedData();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('customerFullName', 'Budi Santoso')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'budi@example.test')
            ->set('customerAddress', 'Jl. Contoh No. 1, Jakarta')
            ->set('customerRelationship', BookingRelationshipCode::ANAK)
            ->set('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Almarhum Contoh')
            ->set('deceasedDateOfBirth', '1950-01-01')
            ->set('deceasedDateOfDeath', '2026-01-01')
            ->set('deceasedRelationship', BookingRelationshipCode::ANAK)
            ->call('saveStep2');

        $versionAfterFirst = BookingDraft::query()->findOrFail($draftId)->version;

        $component->call('saveStep2');

        $this->assertSame(
            $versionAfterFirst,
            BookingDraft::query()->findOrFail($draftId)->version,
            'A double-submitted identical save must replay, not write again.',
        );
    }

    public function test_a_save_carrying_different_data_still_applies_after_a_repeat(): void
    {
        $draftId = $this->draftAtCustomerAndDeceasedData();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('customerFullName', 'Budi Santoso')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'budi@example.test')
            ->set('customerAddress', 'Jl. Contoh No. 1, Jakarta')
            ->set('customerRelationship', BookingRelationshipCode::ANAK)
            ->set('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Almarhum Contoh')
            ->set('deceasedDateOfBirth', '1950-01-01')
            ->set('deceasedDateOfDeath', '2026-01-01')
            ->set('deceasedRelationship', BookingRelationshipCode::ANAK)
            ->call('saveStep2')
            ->call('saveStep2')
            // A corrected value — makes the third call's payload differ
            // from the first two, which replayed identically.
            ->set('customerFullName', 'Budi Santoso Koreksi')
            ->call('saveStep2')
            ->assertHasNoErrors();

        $this->assertSame('Budi Santoso Koreksi', BookingDraft::query()->findOrFail($draftId)->customer_full_name);
    }

    // =====================================================================
    // Optimistic versioning
    // =====================================================================

    public function test_a_draft_changed_in_another_tab_is_reported_and_reloaded_instead_of_overwritten(): void
    {
        $draftId = $this->draftAtCustomerAndDeceasedData();

        // This tab hydrated at the draft's current version...
        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $staleVersion = $component->get('version');

        // ...and another tab saved the merged customer+deceased step in the
        // meantime.
        (new SaveBookingDraftStep)(
            BookingDraft::query()->findOrFail($draftId),
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            [
                'customer_full_name' => 'Tab Lain',
                'customer_mobile' => '081200000001',
                'customer_email' => 'tablain@example.test',
                'customer_address' => 'Jl. Tab Lain No. 1',
                'customer_relationship' => BookingRelationshipCode::ANAK,
                'customer_contact_channel' => BookingContactChannel::WHATSAPP,
                'privacy_notice_accepted' => true,
                'deceased_full_name' => 'Almarhum Tab Lain',
                'deceased_date_of_birth' => '1950-01-01',
                'deceased_date_of_death' => '2026-01-01',
                'deceased_relationship' => BookingRelationshipCode::ANAK,
            ],
            'other-tab-key',
        );

        $component
            ->set('customerFullName', 'Tab Ini')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'tabini@example.test')
            ->set('customerAddress', 'Jl. Tab Ini No. 1')
            ->set('customerRelationship', BookingRelationshipCode::ANAK)
            ->set('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Almarhum Tab Ini')
            ->set('deceasedDateOfBirth', '1950-01-01')
            ->set('deceasedDateOfDeath', '2026-01-01')
            ->set('deceasedRelationship', BookingRelationshipCode::ANAK)
            ->call('saveStep2')
            ->assertHasErrors(['draft'])
            ->assertSet('autosaveState', 'failed');

        $this->assertGreaterThan($staleVersion, $component->get('version'), 'The component must re-hydrate to the latest state.');
        $this->assertSame('Tab Lain', $component->get('customerFullName'));
    }

    // =====================================================================
    // AC13 — step state is not client-writable
    // =====================================================================

    public function test_the_current_step_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(BookingWizard::class)->set('currentStep', BookingWizardStep::PAYMENT);
    }

    public function test_the_completed_steps_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(BookingWizard::class)->set('completedSteps', [1, 2, 3, 4]);
    }

    public function test_the_draft_id_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(BookingWizard::class)->set('draftId', $this->draftAtCustomerAndDeceasedData());
    }

    public function test_a_skipped_step_is_rejected_server_side_even_for_a_caller_that_bypasses_the_ui(): void
    {
        // The client half is `#[Locked]`; this is the server half. A draft
        // that has not completed DISCOVERY cannot save
        // CUSTOMER_AND_DECEASED_DATA. Created directly via `StartBookingDraft`
        // (not through `saveStep1()`), so DISCOVERY genuinely never ran.
        $draft = (new StartBookingDraft)();

        Livewire::test(BookingWizard::class, ['draftId' => $draft->id])
            ->call('saveStep2')
            ->assertHasErrors(['step'])
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);

        $this->assertNull(BookingDraft::query()->findOrFail($draft->id)->customer_full_name);
    }

    // =====================================================================
    // Honest feedback (M6/M7)
    // =====================================================================

    public function test_a_save_without_a_draft_says_so_instead_of_doing_nothing_visible(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep2')
            ->assertHasErrors(['draft'])
            ->assertSet('autosaveState', 'failed')
            ->assertSee('Sesi pemesanan Anda telah berakhir.');
    }

    public function test_navigating_to_another_step_clears_a_stale_saved_indicator(): void
    {
        $draftId = $this->draftAtCustomerAndDeceasedData();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('customerFullName', 'Budi Santoso')
            ->set('customerMobile', '081234567890')
            ->set('customerEmail', 'budi@example.test')
            ->set('customerAddress', 'Jl. Contoh No. 1, Jakarta')
            ->set('customerRelationship', BookingRelationshipCode::ANAK)
            ->set('customerContactChannel', BookingContactChannel::WHATSAPP)
            ->set('privacyNoticeAccepted', true)
            ->set('deceasedFullName', 'Almarhum Contoh')
            ->set('deceasedDateOfBirth', '1950-01-01')
            ->set('deceasedDateOfDeath', '2026-01-01')
            ->set('deceasedRelationship', BookingRelationshipCode::ANAK)
            ->call('saveStep2')
            ->assertSet('autosaveState', 'saved')
            ->call('goToStep', BookingWizardStep::DISCOVERY)
            ->assertSet('autosaveState', 'idle')
            ->assertDontSee('Tersimpan');
    }
}
