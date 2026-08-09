<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Step 2's SECOND-LEVEL choice, for cemeteries that have package/class rows.
 *
 * `SaveBookingDraftStep::validateCemetery()` requires a `cemetery_package_id`
 * whenever the chosen cemetery has active packages
 * (`booking-wizard-fields.md` §Step 2: "package/class when applicable";
 * `cemetery-directory-and-availability` AC6). The seed migration
 * (`2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`) gives
 * active packages to two REAL, PUBLISHED, PICKABLE cemeteries — TPU Jakarta
 * Menteng and TPU Depok Sawangan — so before the picker existed, a visitor
 * who chose either one hit a validation error the screen offered no way to
 * satisfy: an unescapable dead end on the happy path, not an edge case.
 */
final class BookingWizardStepTwoPackagesTest extends TestCase
{
    use RefreshDatabase;

    private function menteng(): Cemetery
    {
        $cemetery = Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->firstOrFail();

        $this->assertSame(LaunchCityCode::JAKARTA, $cemetery->city);
        $this->assertTrue(
            CemeteryPublicQuery::activePackages($cemetery)->isNotEmpty(),
            'Fixture assumption: TPU Jakarta Menteng has active packages.',
        );

        return $cemetery;
    }

    private function draftAtStep2(string $cityCode = LaunchCityCode::JAKARTA): string
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', $cityCode)
            ->get('draftId');

        $this->assertIsString($draftId);

        return $draftId;
    }

    public function test_a_cemetery_with_packages_renders_each_package_as_its_own_choice(): void
    {
        $cemetery = $this->menteng();
        $component = Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtStep2()]);

        $component->assertSee($cemetery->name);

        foreach (CemeteryPublicQuery::activePackages($cemetery) as $package) {
            $component->assertSee($package->name);
            $component->assertSeeHtml("saveStep2('{$cemetery->id}', {$package->id})");
        }
    }

    public function test_a_package_class_label_is_shown_so_two_classes_of_one_package_are_distinguishable(): void
    {
        $cemetery = $this->menteng();

        // The seed gives Menteng "Makam Tumpang" three times — package-level,
        // Kelas A and Kelas B. Without the class label they would render as
        // three identical buttons.
        $classLabels = CemeteryPublicQuery::activePackages($cemetery)
            ->pluck('class_label')
            ->filter()
            ->values();

        $this->assertNotEmpty($classLabels, 'Fixture assumption: Menteng has at least one class-level package row.');

        $component = Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtStep2()]);

        foreach ($classLabels as $classLabel) {
            $component->assertSee($classLabel);
        }
    }

    public function test_a_cemetery_with_packages_is_never_offered_as_a_bare_whole_card_choice(): void
    {
        $cemetery = $this->menteng();

        // The dead end itself: a whole-card `saveStep2('<id>')` with no
        // package argument can only ever be rejected for this cemetery.
        Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtStep2()])
            ->assertDontSeeHtml("saveStep2('{$cemetery->id}')");
    }

    public function test_choosing_a_package_advances_to_step_3(): void
    {
        $cemetery = $this->menteng();
        $package = CemeteryPublicQuery::activePackages($cemetery)->firstOrFail();

        Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtStep2()])
            ->call('saveStep2', $cemetery->id, $package->id)
            ->assertHasNoErrors()
            ->assertSet('currentStep', BookingWizardStep::SERVICE_TYPE)
            ->assertSet('cemeteryPackageId', $package->id);
    }

    public function test_the_chosen_package_is_persisted_on_the_draft(): void
    {
        $cemetery = $this->menteng();
        $package = CemeteryPublicQuery::activePackages($cemetery)->firstOrFail();
        $draftId = $this->draftAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id, $package->id);

        $this->assertDatabaseHas('booking_drafts', [
            'id' => $draftId,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => $package->id,
        ]);
    }

    public function test_a_cemetery_with_packages_still_rejects_a_package_less_submission(): void
    {
        // The server-side rule the picker exists to satisfy is unchanged —
        // rendering the choice is not the same as trusting the client.
        Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtStep2()])
            ->call('saveStep2', $this->menteng()->id)
            ->assertHasErrors(['cemetery_package_id'])
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);
    }

    public function test_a_package_belonging_to_another_cemetery_is_rejected(): void
    {
        $foreignPackage = CemeteryPackage::query()
            ->where('cemetery_id', '!=', $this->menteng()->id)
            ->where('is_active', true)
            ->firstOrFail();

        Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtStep2()])
            ->call('saveStep2', $this->menteng()->id, $foreignPackage->id)
            ->assertHasErrors(['cemetery_package_id']);
    }

    public function test_a_cemetery_without_packages_keeps_the_single_whole_card_choice(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtStep2()])
            ->assertSeeHtml("saveStep2('{$cemetery->id}')")
            ->call('saveStep2', $cemetery->id)
            ->assertHasNoErrors()
            ->assertSet('currentStep', BookingWizardStep::SERVICE_TYPE);
    }
}
