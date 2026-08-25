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
     * RENAMED from `test_every_seeded_row_has_plausible_dummy_price_map_and_
     * photo_data`, which asserted the OLD literal per-cemetery coordinates
     * and maps URLs from the original seed. Both are now `null` by
     * approved design: `CemeteryExampleData`'s honesty framing refuses to
     * invent precise-looking coordinates for a fictional address, so the
     * backfill sets price/photo/price_source only. This test now asserts
     * the NEW dummy values are present and internally plausible, and that
     * the coordinate/map columns stay NULL — the honest-empty state, not a
     * fabrication gap.
     */
    public function test_every_seeded_row_has_plausible_dummy_price_and_photo_but_never_fabricated_coordinates(): void
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

            // Coordinates and the maps URL are ALWAYS null by design (see
            // `CemeteryExampleData`'s honesty framing) — inventing
            // precise-looking coordinates for a fictional address would be
            // a false-precision claim, so the honest state is an explicit
            // absence, not a placeholder.
            $this->assertNull($cemetery->latitude, "slug [{$cemetery->slug}] must not fabricate a latitude");
            $this->assertNull($cemetery->longitude, "slug [{$cemetery->slug}] must not fabricate a longitude");
            $this->assertNull($cemetery->google_maps_url, "slug [{$cemetery->slug}] must not fabricate a maps URL");
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

    /**
     * RENAMED from `test_google_maps_url_is_set_from_the_dummy_backfill_
     * for_every_seeded_row`: the backfill no longer sets a maps URL —
     * coordinates and `google_maps_url` are NULL on every seeded row by
     * design (honesty framing, `CemeteryExampleData`). The behaviour worth
     * pinning survives: `googleMapsUrl()` must surface exactly what is
     * stored and must NOT invent a coordinate-derived URL where the
     * coordinates are absent — the honest-empty case stays honest at the
     * model seam, complementing the fallback test above.
     */
    public function test_google_maps_url_never_fabricates_a_link_for_a_seeded_row(): void
    {
        foreach (Cemetery::query()->get() as $cemetery) {
            $this->assertSame($cemetery->google_maps_url, $cemetery->googleMapsUrl());
        }
    }

    /**
     * `embedMapUrl()`'s three real cases, at the model seam rather than
     * through a rendered route — see `Cemetery::embedMapUrl()`'s own doc
     * block. Coordinates take precedence over an explicit URL when both are
     * present, matching `googleMapsUrl()`'s own precedence for the
     * coordinate-derivation branch (though `googleMapsUrl()` itself prefers
     * the explicit URL first — these two methods intentionally differ here
     * because a `query=`-shaped explicit URL's address text and a real
     * coordinate both produce a VALID embed, and the coordinate is the more
     * precise of the two when both exist).
     */
    public function test_embed_map_url_derives_from_real_coordinates(): void
    {
        $cemetery = new Cemetery([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Koordinat',
            'slug' => 'tpu-contoh-koordinat',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'latitude' => '-6.2033000',
            'longitude' => '106.8153500',
        ]);

        $this->assertSame(
            'https://www.google.com/maps?q=-6.2033000,106.8153500&output=embed',
            $cemetery->embedMapUrl()
        );
    }

    public function test_embed_map_url_derives_from_a_query_shaped_explicit_url_when_no_coordinates_exist(): void
    {
        $cemetery = new Cemetery([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh URL',
            'slug' => 'tpu-contoh-url',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=Jl.+Contoh+No.+1',
        ]);

        $this->assertSame(
            'https://www.google.com/maps?q=Jl.+Contoh+No.+1&output=embed',
            $cemetery->embedMapUrl()
        );
    }

    public function test_embed_map_url_is_null_when_neither_coordinates_nor_a_derivable_url_exist(): void
    {
        $noneAtAll = new Cemetery([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Kosong',
            'slug' => 'tpu-contoh-kosong',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $this->assertNull($noneAtAll->embedMapUrl());

        // A real, legitimate explicit URL that just isn't shaped with a
        // `query=` parameter this method can parse back out — must not
        // throw, must not fabricate a fallback, must return null.
        $nonDerivableUrl = new Cemetery([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh URL Lain',
            'slug' => 'tpu-contoh-url-lain',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'google_maps_url' => 'https://maps.google.com/?q=contoh',
        ]);
        $this->assertNull($nonDerivableUrl->embedMapUrl());
    }
}
