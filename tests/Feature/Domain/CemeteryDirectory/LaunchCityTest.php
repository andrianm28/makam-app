<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `LaunchCity` table is the admin-extendable source for the five
 * canonical launch cities (`AGENTS.md` §Mandatory MVP UX) — the seam
 * `CemeteryPublicQuery::launchCities()` and `BookingDraft`'s city
 * validation read from.
 */
final class LaunchCityTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_cities_ordered_by_sort_order(): void
    {
        // `RefreshDatabase` runs every migration, including the
        // `seed_launch_cities` data migration, so the canonical rows are
        // already present — the fixture resets the table before asserting
        // on a custom set.
        LaunchCity::query()->delete();

        LaunchCity::query()->create(['code' => 'BEKASI', 'label' => 'Bekasi', 'sort_order' => 5]);
        LaunchCity::query()->create(['code' => 'JAKARTA', 'label' => 'Jakarta', 'sort_order' => 1]);
        LaunchCity::query()->create(['code' => 'BOGOR', 'label' => 'Bogor', 'sort_order' => 2, 'is_active' => false]);

        $cities = LaunchCityQuery::activeCities();

        $this->assertSame(['JAKARTA', 'BEKASI'], array_column($cities, 'code'));
    }

    public function test_is_known_reads_table_then_constants(): void
    {
        LaunchCity::query()->create(['code' => 'SUKABUMI', 'label' => 'Sukabumi']);

        $this->assertTrue(LaunchCityQuery::isKnown('SUKABUMI'));
        $this->assertTrue(LaunchCityQuery::isKnown('TANGERANG'));
        $this->assertFalse(LaunchCityQuery::isKnown('NONEXISTENT'));
    }

    /**
     * The five canonical rows ship with the table via the data migration —
     * CI and deploy never run `db:seed`, so the seeder class alone would
     * never materialize them in a real environment.
     */
    public function test_the_canonical_five_are_seeded_with_the_table(): void
    {
        $this->assertSame(
            LaunchCityCode::KNOWN_CODES,
            LaunchCity::query()->orderBy('sort_order')->pluck('code')->all(),
        );
    }
}
