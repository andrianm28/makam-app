<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use App\Livewire\Public\Directory\Support\CemeteryAvailabilityIntent;
use App\Livewire\Public\Directory\Support\CemeteryPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * A UI/UX audit (25 Aug 2026) found Step 2's cards rendered ONLY a type
 * badge and a name — every other field design-system.md §3.3's normative
 * Cemetery card spec (PUB-011) requires (photo, address, facilities,
 * attributed price range, availability status) was missing, even though the
 * SAME card content renders correctly one click away on the public cemetery
 * directory (`resources/views/livewire/public/directory/index.blade.php`).
 * This suite proves Step 2 now renders that same content, sourced through
 * the same presenter classes the directory uses
 * (`CemeteryPresenter`/`CemeteryAvailabilityIntent`), for a real seeded
 * cemetery — not a fixture invented for this test.
 */
final class BookingWizardStepTwoCardContentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The TPU/TPS section reveals on the local city choice — the merged
     * DISCOVERY step creates no draft until "Lanjutkan".
     */
    private function atCemeteryChoice(string $cityCode): Testable
    {
        return Livewire::test(BookingWizard::class)->call('selectCity', $cityCode);
    }

    /**
     * `CemeteryFixture::cemetery('open')` — "TPU Bogor 3" — is the plain
     * published example cemetery with no special role, and every seeded
     * cemetery carries a real photo/price backfill
     * (`CemeteryExampleData::backfills()`, applied to all 10 rows by
     * `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`),
     * so this is real fixture data, not a hand-crafted one-off.
     */
    private function openCemetery(): Cemetery
    {
        $cemetery = CemeteryFixture::cemetery('open');

        $this->assertSame(LaunchCityCode::BOGOR, $cemetery->city);

        return $cemetery;
    }

    public function test_step_2_shows_the_cemetery_photo(): void
    {
        $cemetery = $this->openCemetery();
        $photoUrl = CemeteryPresenter::photoUrl($cemetery);
        $this->assertNotNull($photoUrl, 'Fixture assumption: the open example cemetery has a backfilled photo.');

        $this->atCemeteryChoice(LaunchCityCode::BOGOR)
            ->assertSeeHtml('src="'.$photoUrl.'"')
            ->assertSeeHtml('alt="Ilustrasi '.$cemetery->name.'"');
    }

    public function test_step_2_shows_the_cemetery_address_unconditionally(): void
    {
        $cemetery = $this->openCemetery();

        $this->atCemeteryChoice(LaunchCityCode::BOGOR)
            ->assertSee($cemetery->address);
    }

    public function test_step_2_shows_the_attributed_price_range(): void
    {
        $cemetery = $this->openCemetery();
        $priceRange = CemeteryPresenter::priceRange($cemetery);
        $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
        $this->assertNotNull($priceRange, 'Fixture assumption: the open example cemetery has a backfilled price range.');
        $this->assertNotNull($priceAttribution);

        $this->atCemeteryChoice(LaunchCityCode::BOGOR)
            ->assertSee($priceRange)
            ->assertSee('Sumber: '.$priceAttribution['source']);
    }

    public function test_step_2_shows_the_facilities(): void
    {
        $cemetery = $this->openCemetery();
        $facilities = CemeteryPresenter::facilities($cemetery);
        $this->assertNotEmpty($facilities, 'Fixture assumption: the open example cemetery has facilities.');

        $component = $this->atCemeteryChoice(LaunchCityCode::BOGOR);

        foreach ($facilities as $facility) {
            $component->assertSee($facility);
        }
    }

    /**
     * design-system.md §2.3: an indicative price/availability is `neutral`
     * and labelled "Perlu konfirmasi", the same honest-availability
     * convention the directory's own card already follows — never a
     * fabricated `success` state.
     */
    public function test_step_2_shows_the_honest_availability_status(): void
    {
        $this->atCemeteryChoice(LaunchCityCode::BOGOR)
            ->assertSee(CemeteryAvailabilityIntent::NEEDS_CONFIRMATION_LABEL);
    }

    public function test_step_2_still_shows_the_type_badge_and_name(): void
    {
        $cemetery = $this->openCemetery();

        $this->atCemeteryChoice(LaunchCityCode::BOGOR)
            ->assertSee($cemetery->type)
            ->assertSee($cemetery->name);
    }

    /**
     * The selection control itself must survive the richer content — the
     * exact `selectCemetery('<id>')` wire:click call
     * `BookingWizardStepTwoPackagesTest` already asserts for the two-level
     * (package) case must still work here for the single-choice case.
     */
    public function test_the_cemetery_selection_control_still_works(): void
    {
        $cemetery = $this->openCemetery();

        $this->atCemeteryChoice(LaunchCityCode::BOGOR)
            ->assertSeeHtml("selectCemetery('{$cemetery->id}')")
            ->call('selectCemetery', $cemetery->id)
            ->assertHasNoErrors()
            ->assertSet('cemeteryId', $cemetery->id)
            ->assertSee('Pilih Jenis Layanan');
    }
}
