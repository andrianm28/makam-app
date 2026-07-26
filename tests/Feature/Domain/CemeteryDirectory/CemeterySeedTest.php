<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` seeds ten
 * EXAMPLE cemeteries (fictional — see that migration's own doc block),
 * two per launch city. Backs requirements.md AC1 (all five launch cities
 * represented) and AC2 (published/draft filtering).
 */
final class CemeterySeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_ten_cemeteries_are_seeded(): void
    {
        $this->assertDatabaseCount('cemeteries', 10);
    }

    public function test_every_launch_city_has_at_least_one_seeded_cemetery(): void
    {
        foreach (LaunchCityCode::KNOWN_CODES as $city) {
            $count = Cemetery::query()->where('city', $city)->count();

            $this->assertGreaterThan(
                0,
                $count,
                "AC1 violation: launch city [{$city}] has no seeded cemetery."
            );
        }
    }

    public function test_every_seeded_cemetery_has_a_known_type_and_unique_slug(): void
    {
        $cemeteries = Cemetery::query()->get();

        $this->assertSame(10, $cemeteries->count());

        foreach ($cemeteries as $cemetery) {
            $this->assertTrue(CemeteryType::isKnown($cemetery->type));
        }

        $this->assertSame(
            $cemeteries->pluck('slug')->unique()->count(),
            $cemeteries->count(),
            'Every seeded cemetery slug must be unique.'
        );
    }

    public function test_nine_are_published_and_one_is_deliberately_draft(): void
    {
        $this->assertDatabaseCount('cemeteries', 9, ['publication_status' => CemeteryPublicationStatus::PUBLISHED]);
        $this->assertDatabaseCount('cemeteries', 1, ['publication_status' => CemeteryPublicationStatus::DRAFT]);

        $draft = Cemetery::query()->where('publication_status', CemeteryPublicationStatus::DRAFT)->first();
        $this->assertSame('tps-bekasi-harapan-indah', $draft?->slug);
    }

    public function test_scope_published_excludes_the_draft_row(): void
    {
        $published = Cemetery::published()->pluck('slug');

        $this->assertCount(9, $published);
        $this->assertNotContains('tps-bekasi-harapan-indah', $published);
    }

    public function test_scope_in_city_and_scope_of_type_compose_with_published(): void
    {
        $jakartaTpu = Cemetery::published()->inCity(LaunchCityCode::JAKARTA)->ofType(CemeteryType::TPU)->get();

        $this->assertCount(1, $jakartaTpu);
        $this->assertSame('tpu-jakarta-menteng', $jakartaTpu->first()->slug);
    }

    public function test_no_seeded_row_fabricates_price_photo_or_coordinates(): void
    {
        // Honesty discipline documented in the seed migration's own doc
        // block: no real price/photo/coordinate data exists, so none is
        // invented for these fictional example rows.
        $cemeteries = Cemetery::query()->get();

        foreach ($cemeteries as $cemetery) {
            $this->assertNull($cemetery->price_min);
            $this->assertNull($cemetery->price_max);
            $this->assertNull($cemetery->price_source);
            $this->assertNull($cemetery->primary_photo_path);
            $this->assertNull($cemetery->latitude);
            $this->assertNull($cemetery->longitude);
        }
    }

    public function test_google_maps_url_falls_back_to_null_without_blocking_the_address(): void
    {
        $cemetery = Cemetery::query()->first();

        // AC11: map provider absence must never block the textual address.
        $this->assertNull($cemetery->googleMapsUrl());
        $this->assertNotEmpty($cemetery->address);
    }
}
