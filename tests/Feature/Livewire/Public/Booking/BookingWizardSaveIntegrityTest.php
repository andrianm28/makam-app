<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
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

    private function draftAtStep2(): string
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        $this->assertIsString($draftId);

        return $draftId;
    }

    // =====================================================================
    // Idempotency — the key is derived, not random
    // =====================================================================

    public function test_repeating_the_identical_save_is_a_no_op_rather_than_a_second_write(): void
    {
        $draftId = $this->draftAtStep2();
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id);

        $versionAfterFirst = BookingDraft::query()->findOrFail($draftId)->version;

        $component->call('saveStep2', $cemetery->id);

        $this->assertSame(
            $versionAfterFirst,
            BookingDraft::query()->findOrFail($draftId)->version,
            'A double-submitted identical save must replay, not write again.',
        );
    }

    public function test_a_save_carrying_different_data_still_applies_after_a_repeat(): void
    {
        $draftId = $this->draftAtStep2();
        $first = $this->jakartaCemeteryWithoutPackages();

        // A different Jakarta cemetery — this one has packages, so the
        // corrected choice also carries a package id, which makes the third
        // call differ from the first two in BOTH arguments.
        $second = Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->firstOrFail();
        $package = CemeteryPublicQuery::activePackages($second)->firstOrFail();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $first->id)
            ->call('saveStep2', $first->id)
            ->call('saveStep2', $second->id, $package->id)
            ->assertHasNoErrors();

        $this->assertSame($second->id, BookingDraft::query()->findOrFail($draftId)->cemetery_id);
    }

    // =====================================================================
    // Optimistic versioning
    // =====================================================================

    public function test_a_draft_changed_in_another_tab_is_reported_and_reloaded_instead_of_overwritten(): void
    {
        $draftId = $this->draftAtStep2();
        $cemetery = $this->jakartaCemeteryWithoutPackages();

        // This tab hydrated at the draft's current version...
        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);
        $staleVersion = $component->get('version');

        // ...and another tab saved step 2 in the meantime.
        (new SaveBookingDraftStep)(
            BookingDraft::query()->findOrFail($draftId),
            BookingWizardStep::CEMETERY,
            ['cemetery_id' => $cemetery->id],
            'other-tab-key',
        );

        $component->call('saveStep2', $cemetery->id)
            ->assertHasErrors(['draft'])
            ->assertSet('autosaveState', 'failed');

        $this->assertGreaterThan($staleVersion, $component->get('version'), 'The component must re-hydrate to the latest state.');
        $this->assertSame($cemetery->id, $component->get('cemeteryId'));
    }

    // =====================================================================
    // AC13 — step state is not client-writable
    // =====================================================================

    public function test_the_current_step_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(BookingWizard::class)->set('currentStep', BookingWizardStep::SUMMARY);
    }

    public function test_the_completed_steps_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(BookingWizard::class)->set('completedSteps', [1, 2, 3, 4]);
    }

    public function test_the_draft_id_cannot_be_set_from_the_client(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(BookingWizard::class)->set('draftId', $this->draftAtStep2());
    }

    public function test_a_skipped_step_is_rejected_server_side_even_for_a_caller_that_bypasses_the_ui(): void
    {
        // The client half is `#[Locked]`; this is the server half. A draft
        // that only ever completed step 1 cannot save step 3.
        $draftId = $this->draftAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep3', 'NEW_GRAVE')
            ->assertHasErrors(['step'])
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);

        $this->assertNull(BookingDraft::query()->findOrFail($draftId)->service_type);
    }

    // =====================================================================
    // Honest feedback (M6/M7)
    // =====================================================================

    public function test_a_save_without_a_draft_says_so_instead_of_doing_nothing_visible(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep3', 'NEW_GRAVE')
            ->assertHasErrors(['draft'])
            ->assertSet('autosaveState', 'failed')
            ->assertSee('Sesi pemesanan Anda telah berakhir.');
    }

    public function test_navigating_to_another_step_clears_a_stale_saved_indicator(): void
    {
        $draftId = $this->draftAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $this->jakartaCemeteryWithoutPackages()->id)
            ->assertSet('autosaveState', 'saved')
            ->call('goToStep', BookingWizardStep::LOCATION)
            ->assertSet('autosaveState', 'idle')
            ->assertDontSee('Tersimpan');
    }
}
