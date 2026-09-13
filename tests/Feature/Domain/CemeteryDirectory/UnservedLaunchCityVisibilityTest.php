<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use App\Livewire\Public\Booking\BookingWizard;
use App\Livewire\Public\Directory\CemeteryDirectoryIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FN-2 — the first option in the booking funnel dead-ended.
 *
 * `launch_cities` held six rows on the live site: the five canonical ones
 * from the seed migration (`sort_order >= 1`) and a hand-created `SUKABUMI`
 * with `sort_order = 0`, which therefore sorted FIRST. No cemetery anywhere
 * has `city = 'SUKABUMI'`, so the first and most obvious click on
 * `/pemesanan-makam` landed a grieving family on "Belum ada TPU/TPS
 * terdaftar di kota ini."
 *
 * The rule under test is deliberately asymmetric, and both halves matter:
 *
 *   - a CANONICAL launch city is listed whether or not it has inventory —
 *     `AGENTS.md` §Mandatory MVP UX and the spec's "No hidden omission of a
 *     required MVP city" negative criterion;
 *   - an ADMIN-ADDED city is listed only once it has a published cemetery.
 *
 * A test that only asserted the second half would be satisfied by a filter
 * that also hides Bekasi on a thin data day, which is the exact regression
 * `CemeteryDirectoryIndexRouteTest::
 * test_a_launch_city_keeps_its_filter_even_with_no_published_cemeteries`
 * exists to prevent. Both halves are asserted here for both public
 * surfaces the brief names.
 */
final class UnservedLaunchCityVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const EXTRA_CODE = 'SUKABUMI';

    private const EXTRA_LABEL = 'Sukabumi';

    protected function setUp(): void
    {
        parent::setUp();

        // Both Livewire renders below go through layouts/app.blade.php's
        // `@vite(...)`; this host has no frontend build, same as every other
        // public Livewire test in this suite.
        $this->withoutVite();
    }

    /**
     * Reproduces the live row exactly, including the `sort_order = 0` that
     * put it ahead of all five seeded cities.
     */
    private function createUnservedAdminCity(): LaunchCity
    {
        return LaunchCity::query()->create([
            'code' => self::EXTRA_CODE,
            'label' => self::EXTRA_LABEL,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function publishACemeteryIn(string $cityCode): Cemetery
    {
        return Cemetery::factory()->create([
            'city' => $cityCode,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
        ]);
    }

    public function test_an_admin_added_city_with_no_published_cemetery_is_not_offered(): void
    {
        $this->createUnservedAdminCity();

        $this->assertSame(
            0,
            Cemetery::query()->published()->inCity(self::EXTRA_CODE)->count(),
            'Precondition: the added city must genuinely have no published cemetery.'
        );

        $this->assertNotContains(
            self::EXTRA_CODE,
            array_column(CemeteryPublicQuery::launchCities(), 'code'),
            'An admin-added city with no published cemetery must not reach any public city list.'
        );

        Livewire::test(BookingWizard::class)->assertDontSee(self::EXTRA_LABEL);
        Livewire::test(CemeteryDirectoryIndex::class)->assertDontSee(self::EXTRA_LABEL);
    }

    public function test_an_admin_added_city_with_a_published_cemetery_is_offered(): void
    {
        $this->createUnservedAdminCity();
        $this->publishACemeteryIn(self::EXTRA_CODE);

        $this->assertContains(
            self::EXTRA_CODE,
            array_column(CemeteryPublicQuery::launchCities(), 'code'),
            'The row is never deleted, so the city must appear by itself as soon as it is served.'
        );

        Livewire::test(BookingWizard::class)->assertSee(self::EXTRA_LABEL);
        Livewire::test(CemeteryDirectoryIndex::class)->assertSee(self::EXTRA_LABEL);
    }

    /**
     * The half of the rule that must NOT change. Bekasi is emptied out the
     * same way `CemeteryDirectoryIndexRouteTest` empties it, and must still
     * be offered — a canonical launch city is a statement about where the
     * platform operates, not a summary of today's inventory.
     */
    public function test_a_canonical_launch_city_is_still_offered_with_no_published_cemetery(): void
    {
        Cemetery::query()
            ->inCity(LaunchCityCode::BEKASI)
            ->update(['publication_status' => CemeteryPublicationStatus::UNPUBLISHED]);

        $this->assertSame(
            0,
            Cemetery::query()->published()->inCity(LaunchCityCode::BEKASI)->count(),
            'Precondition: Bekasi must genuinely have no published cemetery.'
        );

        $this->assertContains(
            LaunchCityCode::BEKASI,
            array_column(CemeteryPublicQuery::launchCities(), 'code'),
        );

        Livewire::test(BookingWizard::class)->assertSee('Bekasi');
        Livewire::test(CemeteryDirectoryIndex::class)->assertSee('Bekasi');
    }

    /**
     * The admin form must keep seeing the raw catalogue: an operator has to
     * be able to assign the FIRST cemetery in a new city, or the city could
     * never stop being empty and the rule above would be a one-way door.
     */
    public function test_the_admin_catalogue_still_contains_the_unserved_city(): void
    {
        $this->createUnservedAdminCity();

        $this->assertContains(
            self::EXTRA_CODE,
            array_column(LaunchCityQuery::activeCities(), 'code'),
            'Hiding the city from the ADMIN catalogue would make it impossible to onboard its first cemetery.'
        );
    }
}
