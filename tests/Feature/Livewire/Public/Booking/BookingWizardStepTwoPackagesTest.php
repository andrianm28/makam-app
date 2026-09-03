<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * The TPU/TPS section's SECOND-LEVEL choice, for cemeteries that have
 * package/class rows.
 *
 * `SaveBookingDraftStep::validateCemetery()` requires a `cemetery_package_id`
 * whenever the chosen cemetery has active packages
 * (`booking-wizard-fields.md` §Step 2: "package/class when applicable";
 * `cemetery-directory-and-availability` AC6). The example-data generator
 * gives active packages to two published, pickable example cemeteries
 * (the `package` role, indices 0 and 1) — so before the picker
 * existed, a visitor who chose either one hit a validation error the screen
 * offered no way to satisfy: an unescapable dead end on the happy path, not
 * an edge case.
 */
final class BookingWizardStepTwoPackagesTest extends TestCase
{
    use RefreshDatabase;

    private function packagesCemetery(): Cemetery
    {
        $cemetery = CemeteryFixture::cemetery('package', 0);

        $this->assertSame(LaunchCityCode::JAKARTA, $cemetery->city);
        $this->assertTrue(
            CemeteryPublicQuery::activePackages($cemetery)->isNotEmpty(),
            'Fixture assumption: the packages example cemetery has active packages.',
        );

        return $cemetery;
    }

    /**
     * The TPU/TPS section is revealed by the local city choice now — no draft
     * exists at this point in the journey at all, since the merged DISCOVERY
     * step persists nothing until "Lanjutkan".
     */
    private function atCemeteryChoice(string $cityCode = LaunchCityCode::JAKARTA): Testable
    {
        return Livewire::test(BookingWizard::class)->call('selectCity', $cityCode);
    }

    public function test_a_cemetery_with_packages_renders_each_package_as_its_own_choice(): void
    {
        $cemetery = $this->packagesCemetery();
        $component = $this->atCemeteryChoice();

        $component->assertSee($cemetery->name);

        foreach (CemeteryPublicQuery::activePackages($cemetery) as $package) {
            $component->assertSee($package->name);
            $component->assertSeeHtml("selectCemetery('{$cemetery->id}', {$package->id})");
        }
    }

    public function test_a_package_class_label_is_shown_so_two_classes_of_one_package_are_distinguishable(): void
    {
        $cemetery = $this->packagesCemetery();

        // The packages example cemetery gives "Makam Tumpang" three times —
        // package-level, Kelas A and Kelas B. Without the class label they
        // would render as three identical buttons.
        $classLabels = CemeteryPublicQuery::activePackages($cemetery)
            ->pluck('class_label')
            ->filter()
            ->values();

        $this->assertNotEmpty($classLabels, 'Fixture assumption: the packages example cemetery has at least one class-level package row.');

        $component = $this->atCemeteryChoice();

        foreach ($classLabels as $classLabel) {
            $component->assertSee($classLabel);
        }
    }

    public function test_a_cemetery_with_packages_is_never_offered_as_a_bare_whole_card_choice(): void
    {
        $cemetery = $this->packagesCemetery();

        // The dead end itself: a whole-card `selectCemetery('<id>')` with no
        // package argument can only ever be rejected for this cemetery when
        // the DISCOVERY save runs.
        $this->atCemeteryChoice()
            ->assertDontSeeHtml("selectCemetery('{$cemetery->id}')");
    }

    public function test_choosing_a_package_reveals_the_service_type_section_without_saving(): void
    {
        $cemetery = $this->packagesCemetery();
        $package = CemeteryPublicQuery::activePackages($cemetery)->firstOrFail();

        $this->atCemeteryChoice()
            ->call('selectCemetery', $cemetery->id, $package->id)
            ->assertHasNoErrors()
            ->assertSet('cemeteryId', $cemetery->id)
            ->assertSet('cemeteryPackageId', $package->id)
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY)
            ->assertSee('Pilih Jenis Layanan');

        $this->assertDatabaseCount('booking_drafts', 0);
    }

    public function test_the_chosen_package_is_persisted_by_the_discovery_save(): void
    {
        $cemetery = $this->packagesCemetery();
        $package = CemeteryPublicQuery::activePackages($cemetery)->firstOrFail();

        $draftId = $this->atCemeteryChoice()
            ->call('selectCemetery', $cemetery->id, $package->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery')
            ->get('draftId');

        $this->assertDatabaseHas('booking_drafts', [
            'id' => $draftId,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => $package->id,
        ]);
    }

    public function test_a_cemetery_with_packages_still_rejects_a_package_less_submission(): void
    {
        // The server-side rule the picker exists to satisfy is unchanged —
        // rendering the choice is not the same as trusting the client. The
        // local setter accepts anything; the ONE save is what refuses, and
        // the customer stays on DISCOVERY with the field error.
        $this->atCemeteryChoice()
            ->call('selectCemetery', $this->packagesCemetery()->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery')
            ->assertHasErrors(['cemetery_package_id'])
            ->assertSet('currentStep', BookingWizardStep::DISCOVERY);
    }

    public function test_a_package_belonging_to_another_cemetery_is_rejected(): void
    {
        $foreignPackage = CemeteryPackage::query()
            ->where('cemetery_id', '!=', $this->packagesCemetery()->id)
            ->where('is_active', true)
            ->firstOrFail();

        $this->atCemeteryChoice()
            ->call('selectCemetery', $this->packagesCemetery()->id, $foreignPackage->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery')
            ->assertHasErrors(['cemetery_package_id']);
    }

    public function test_a_cemetery_without_packages_keeps_the_single_whole_card_choice(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $this->atCemeteryChoice()
            ->assertSeeHtml("selectCemetery('{$cemetery->id}')")
            ->call('selectCemetery', $cemetery->id)
            ->call('selectServiceType', BookingServiceType::NEW_GRAVE)
            ->call('continueFromDiscovery')
            ->assertHasNoErrors()
            ->assertSet('currentStep', BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);
    }
}
