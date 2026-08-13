<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `App\Support\ExampleData\CemeteryExampleData` generates ten EXAMPLE
 * cemeteries (fictional — see that class's own doc block), materialized by
 * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` on
 * `migrate`. Backs requirements.md AC1 (all five launch cities represented)
 * and AC2 (published/draft filtering).
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
        // assertDatabaseCount()'s third parameter is a connection name, not
        // a where-conditions array (Illuminate\Foundation\Testing\Concerns\
        // InteractsWithDatabase::assertDatabaseCount()) — a plain count
        // query is the correct way to assert a filtered count.
        $this->assertSame(
            9,
            Cemetery::query()->where('publication_status', CemeteryPublicationStatus::PUBLISHED)->count()
        );
        $this->assertSame(
            1,
            Cemetery::query()->where('publication_status', CemeteryPublicationStatus::DRAFT)->count()
        );

        $draft = Cemetery::query()->where('publication_status', CemeteryPublicationStatus::DRAFT)->first();
        $this->assertSame(CemeteryExampleData::DRAFT_SLUG, $draft?->slug);
    }

    public function test_scope_published_excludes_the_draft_row(): void
    {
        $published = Cemetery::published()->pluck('slug');

        $this->assertCount(9, $published);
        $this->assertNotContains(CemeteryExampleData::DRAFT_SLUG, $published);
    }

    public function test_scope_in_city_and_scope_of_type_compose_with_published(): void
    {
        $jakartaTpu = Cemetery::published()->inCity(LaunchCityCode::JAKARTA)->ofType(CemeteryType::TPU)->get();

        $this->assertCount(1, $jakartaTpu);
        $this->assertSame(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0], $jakartaTpu->first()->slug);
    }

    /**
     * RENAMED from `test_no_seeded_row_fabricates_price_photo_or_
     * coordinates`, which asserted these six columns were `null` for every
     * seeded row. That was correct honesty discipline at S4-T1 time (no
     * real cemetery partnership/price/photo/coordinate data existed, and
     * inventing any would have been fabricated business data — see the
     * original seed migration's own doc block,
     * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`).
     *
     * The premise changed by explicit user authorization: `dev.makam.co.id`
     * is a real, public, non-production host by deliberate decision
     * (`docs/operations/dev-staging-environment.md` §4/§5, ADR-0031) where
     * clearly-fictional DUMMY/DEMO data is the correct content type, not a
     * fabrication risk. `2026_07_26_210000_backfill_dummy_map_price_and_
     * photo_for_seeded_cemeteries.php` (its own doc block carries the same
     * reasoning, kept in sync with this one) backfills these columns with
     * placeholder-but-plausible values so the public directory has
     * something to render end-to-end on that host. This test now asserts
     * the NEW dummy values are present and internally plausible, not that
     * they are absent.
     */
    public function test_every_seeded_row_has_plausible_dummy_price_map_and_photo_data(): void
    {
        $cemeteries = Cemetery::query()->get();

        $this->assertSame(10, $cemeteries->count());

        foreach ($cemeteries as $cemetery) {
            $this->assertNotNull($cemetery->price_min, "slug [{$cemetery->slug}] missing price_min");
            $this->assertNotNull($cemetery->price_max, "slug [{$cemetery->slug}] missing price_max");
            $this->assertLessThan(
                (float) $cemetery->price_max,
                (float) $cemetery->price_min,
                "slug [{$cemetery->slug}]: price_min must be less than price_max"
            );
            $this->assertSame('IDR', $cemetery->price_currency);
            $this->assertNotNull($cemetery->price_source);
            $this->assertStringContainsString(
                'data contoh',
                (string) $cemetery->price_source,
                "slug [{$cemetery->slug}]: price_source must be labelled as example/estimate data, not a real named authority"
            );

            $this->assertNotNull($cemetery->primary_photo_path);
            $this->assertStringStartsWith('images/cemeteries/', (string) $cemetery->primary_photo_path);
            $this->assertStringEndsWith('.svg', (string) $cemetery->primary_photo_path);

            // Rough Indonesia bounding box — generous, not a precise
            // per-city check, since these coordinates are deliberately
            // rough/generic (see the backfill migration's own doc block).
            $this->assertNotNull($cemetery->latitude);
            $this->assertNotNull($cemetery->longitude);
            $this->assertGreaterThanOrEqual(-11.0, (float) $cemetery->latitude);
            $this->assertLessThanOrEqual(6.0, (float) $cemetery->latitude);
            $this->assertGreaterThanOrEqual(95.0, (float) $cemetery->longitude);
            $this->assertLessThanOrEqual(141.0, (float) $cemetery->longitude);

            $this->assertNotNull($cemetery->google_maps_url);
            $this->assertStringStartsWith('https://', (string) $cemetery->google_maps_url);
        }
    }

    /**
     * RENAMED from `test_google_maps_url_falls_back_to_null_without_
     * blocking_the_address`. That test relied on every seeded row having a
     * `null` `google_maps_url`/coordinates to exercise `Cemetery::
     * googleMapsUrl()`'s fallback branch — no longer true now that the
     * dummy-data backfill (see this file's other renamed test) sets an
     * explicit `google_maps_url` on every seeded row. The fallback
     * BEHAVIOUR itself (AC11: a missing map provider must never block the
     * textual address) is still real production logic and still worth
     * testing — this now exercises it directly against an in-memory model
     * instance rather than a persisted seeded row, since no seeded row
     * still represents that "nothing set" case.
     */
    public function test_google_maps_url_falls_back_to_null_without_blocking_the_address(): void
    {
        $cemetery = new Cemetery([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Tanpa Peta',
            'slug' => 'tpu-contoh-tanpa-peta',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh Tanpa Peta No. 1',
        ]);

        // AC11: map provider absence must never block the textual address.
        $this->assertNull($cemetery->googleMapsUrl());
        $this->assertNotEmpty($cemetery->address);
    }

    public function test_google_maps_url_is_set_from_the_dummy_backfill_for_every_seeded_row(): void
    {
        // Complements the fallback test above: every PERSISTED seeded row
        // has an explicit google_maps_url now, so googleMapsUrl() should
        // return that explicit value rather than fall back to null or a
        // coordinate-derived URL.
        foreach (Cemetery::query()->get() as $cemetery) {
            $this->assertSame($cemetery->google_maps_url, $cemetery->googleMapsUrl());
        }
    }
}
