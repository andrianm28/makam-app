<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills `latitude`/`longitude`/`google_maps_url`/`price_min`/
 * `price_max`/`price_source`/`price_effective_at`/`primary_photo_path` on
 * the ten fictional example `cemeteries` rows seeded by
 * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`. That
 * migration is already-run dev data, so this ships as a NEW, additive
 * migration rather than an edit to it (Laravel migration hygiene — never
 * rewrite a migration that has already applied).
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS — read this before adding, copying, or "completing" any
 * row below
 * ---------------------------------------------------------------------------
 * The seed migration's own doc block left these columns `null` for every
 * row because, at S4-T1 time, no real cemetery partnership/operator data
 * existed and inventing coordinates/prices/photos for a fictional address
 * would have been fabricated business data. That reasoning has NOT been
 * reversed — it is still true that none of these ten cemeteries is a real,
 * verified operating TPU/TPS. What changed is the DISPLAY CONTEXT this data
 * is for: the user has explicitly authorized filling these columns with
 * clearly-fictional DUMMY/DEMO data so the public directory has something
 * to render end-to-end on `dev.makam.co.id`.
 *
 * `dev.makam.co.id` is a real, public, non-production dev environment by
 * deliberate decision (`docs/operations/dev-staging-environment.md` §4,
 * "Staging uses synthetic data or a formally approved, irreversibly
 * sanitized dataset"; §5 and ADR-0031, "the user explicitly requested
 * making dev public ... §4's synthetic-data-only rule ... matter[s] more
 * here than before, not less"). Synthetic, clearly-labelled placeholder
 * data is exactly the correct content type for that host — this migration
 * does not claim any of it is real, and every value below is built to read
 * as an example rather than risk being mistaken for verified content
 * later, the same discipline the seed migration itself applied to names/
 * addresses/operator_name.
 *
 * This migration does NOT touch `price_effective_at`'s sibling honesty
 * concern any differently than the rest of this batch: `price_source`
 * below is a generic "this is an estimate/example" string, never a named
 * authority that does not exist — inventing a fake citation (e.g. "per
 * Dinas Pemakaman DKI Jakarta 2026") would be the kind of specific false
 * attribution this project's anti-fabrication discipline forbids, whereas
 * a generic estimate label showing a plausible range honestly is not.
 *
 * A future real-data batch is expected to replace this per-cemetery, row
 * by row, once actual operator partnerships/agreements exist for that
 * specific cemetery — this migration does not block or complicate that;
 * it only sets a `SET` value that a later `UPDATE ... WHERE slug = ...`
 * (or an admin CMS write path, S4-T6) can freely overwrite.
 *
 * ---------------------------------------------------------------------------
 * Coordinates — rough, city-area-level only, not a street-level lookup
 * ---------------------------------------------------------------------------
 * Every seeded cemetery's address is a fictional "Jl. Contoh ..." street
 * (see the seed migration), so there is no real address to geocode.
 * Latitude/longitude below are deliberately rough points within each
 * launch city's general area (2 decimal places — roughly 1 km precision,
 * not the 6-7 decimal precision a real survey-grade or geocoder-derived
 * point would carry), close to but not pretending to be a precise lookup
 * for these fictional street names:
 *   - Jakarta ~ -6.2 / 106.8
 *   - Bogor   ~ -6.6 / 106.8
 *   - Depok   ~ -6.4 / 106.8
 *   - Tangerang ~ -6.2 / 106.6
 *   - Bekasi  ~ -6.2 / 107.0
 *
 * ---------------------------------------------------------------------------
 * `google_maps_url` — a real, honest search link, not a fabricated pin
 * ---------------------------------------------------------------------------
 * Built as a standard Google Maps text-search URL
 * (`https://www.google.com/maps/search/?api=1&query=<name>, <city>,
 * Indonesia`, URL-encoded) from each cemetery's own fictional name and
 * city. This is a real, functioning link — Google will search for that
 * text — it just does not claim to drop a precise pin on a verified real
 * location the way a `.../maps/place/<lat>,<lng>` or a `!3d!4d`-style URL
 * would. `Cemetery::googleMapsUrl()` already prefers an explicit
 * `google_maps_url` over deriving one from coordinates (see that method),
 * so setting this column explicitly is the intended way to populate it.
 *
 * ---------------------------------------------------------------------------
 * Pricing — plausible Indonesian TPU/TPS plot ranges, generic source
 * ---------------------------------------------------------------------------
 * TPU (public/government-operated) rows use a lower, ~3-6 juta IDR range;
 * TPS (private) rows use a higher ~8-22 juta IDR range — ordinary,
 * publicly-known relative pricing for this sector, not a claim about any
 * specific real cemetery's actual price. `price_source` is the same
 * generic "Estimasi internal (data contoh)" ("internal estimate, example
 * data") string for every row — honest about being an estimate/example,
 * naming no fake authority. `price_effective_at` is set to this
 * migration's run time, i.e. "this example figure is presented as of now,"
 * not a claim about when a real price was actually set by an operator.
 *
 * ---------------------------------------------------------------------------
 * `primary_photo_path` — real placeholder SVG illustrations, not a fake
 * photo of a real place
 * ---------------------------------------------------------------------------
 * Four small, hand-authored, non-photorealistic SVG illustrations ship at
 * `public/images/cemeteries/illustration-0{1,2,3,4}-*.svg` (calm green/
 * neutral palette; a generic stone gate, tree grove, path, or low
 * fence-and-garden motif — no specific religious iconography, since
 * Indonesia's cemeteries serve multiple faiths and this app is
 * faith-neutral per its own docs). No new static-asset convention existed
 * in this repository before this batch (`public/` held only `index.php`/
 * `.htaccess`/`favicon.ico`/`robots.txt`); this establishes
 * `public/images/<domain>/...` as that convention, following Laravel's
 * plain public-disk-style layout (files served directly from `public/`,
 * referenced by a path relative to it — e.g. via the `asset()` helper in
 * a later rendering batch). `primary_photo_path` below stores that
 * relative path (`images/cemeteries/illustration-...svg`), consistent with
 * the column's own doc comment ("stores a reference ... not a public
 * URL") in `2026_07_26_190000_create_cemeteries_table.php`. The four
 * illustrations are reused across the ten rows (no requirement for ten
 * unique images) — reusing a small set of generic illustrations across
 * fictional rows is honest; fabricating ten distinct "photos" would
 * overstate how much real per-cemetery visual data exists.
 *
 * ---------------------------------------------------------------------------
 * Test impact
 * ---------------------------------------------------------------------------
 * `tests/Feature/Domain/CemeteryDirectory/CemeterySeedTest.php`'s
 * `test_no_seeded_row_fabricates_price_photo_or_coordinates` asserted these
 * columns were all `null` — that premise is now deliberately false per the
 * authorization above, so that test was renamed and its assertions flipped
 * in the same change as this migration (see that file's own updated doc
 * comment for the identical reasoning, kept in sync with this one).
 *
 * Migration timestamp: `2026_07_26_210000` — after
 * `2026_07_26_200000_create_menu_interaction_events_table.php`, the latest
 * migration in the repository at the time this one was written (verified
 * against the actual `database/migrations/` directory, not assumed).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Shape: [slug, latitude, longitude, google_maps_url, price_min,
        // price_max, primary_photo_path] — price_source is a single literal
        // shared by every row (see the update() call below), not part of
        // this per-row tuple.
        $backfills = [
            [
                'tpu-jakarta-menteng', -6.19, 106.83,
                $this->mapsSearchUrl('TPU Jakarta Menteng', 'Jakarta'),
                4_000_000.00, 7_500_000.00,
                'images/cemeteries/illustration-01-gate.svg',
            ],
            [
                'tps-jakarta-kemang', -6.26, 106.81,
                $this->mapsSearchUrl('TPS Jakarta Kemang', 'Jakarta'),
                12_000_000.00, 22_000_000.00,
                'images/cemeteries/illustration-02-grove.svg',
            ],
            [
                'tpu-bogor-bantarjati', -6.57, 106.81,
                $this->mapsSearchUrl('TPU Bogor Bantarjati', 'Bogor'),
                3_000_000.00, 6_000_000.00,
                'images/cemeteries/illustration-03-path.svg',
            ],
            [
                'tps-bogor-cimanggu', -6.63, 106.79,
                $this->mapsSearchUrl('TPS Bogor Cimanggu', 'Bogor'),
                9_000_000.00, 16_000_000.00,
                'images/cemeteries/illustration-04-garden.svg',
            ],
            [
                'tpu-depok-sawangan', -6.38, 106.76,
                $this->mapsSearchUrl('TPU Depok Sawangan', 'Depok'),
                3_500_000.00, 6_500_000.00,
                'images/cemeteries/illustration-01-gate.svg',
            ],
            [
                'tps-depok-cinere', -6.33, 106.77,
                $this->mapsSearchUrl('TPS Depok Cinere', 'Depok'),
                10_000_000.00, 18_000_000.00,
                'images/cemeteries/illustration-02-grove.svg',
            ],
            [
                'tpu-tangerang-cipondoh', -6.19, 106.69,
                $this->mapsSearchUrl('TPU Tangerang Cipondoh', 'Tangerang'),
                3_200_000.00, 6_200_000.00,
                'images/cemeteries/illustration-03-path.svg',
            ],
            [
                'tps-tangerang-karawaci', -6.23, 106.63,
                $this->mapsSearchUrl('TPS Tangerang Karawaci', 'Tangerang'),
                8_500_000.00, 15_000_000.00,
                'images/cemeteries/illustration-04-garden.svg',
            ],
            [
                'tpu-bekasi-jatiasih', -6.27, 106.98,
                $this->mapsSearchUrl('TPU Bekasi Jatiasih', 'Bekasi'),
                3_000_000.00, 5_800_000.00,
                'images/cemeteries/illustration-01-gate.svg',
            ],
            [
                'tps-bekasi-harapan-indah', -6.15, 107.01,
                $this->mapsSearchUrl('TPS Bekasi Harapan Indah', 'Bekasi'),
                9_500_000.00, 17_000_000.00,
                'images/cemeteries/illustration-02-grove.svg',
            ],
        ];

        foreach ($backfills as [$slug, $latitude, $longitude, $googleMapsUrl, $priceMin, $priceMax, $photoPath]) {
            DB::table('cemeteries')->where('slug', $slug)->update([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'google_maps_url' => $googleMapsUrl,
                'primary_photo_path' => $photoPath,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'price_source' => 'Estimasi internal (data contoh)',
                'price_effective_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $slugs = [
            'tpu-jakarta-menteng', 'tps-jakarta-kemang',
            'tpu-bogor-bantarjati', 'tps-bogor-cimanggu',
            'tpu-depok-sawangan', 'tps-depok-cinere',
            'tpu-tangerang-cipondoh', 'tps-tangerang-karawaci',
            'tpu-bekasi-jatiasih', 'tps-bekasi-harapan-indah',
        ];

        // Restores the seed migration's original honesty-driven NULL state
        // for these columns — this migration is purely additive dummy/demo
        // data on top of that migration's real seed rows, so rolling it
        // back must not delete the cemeteries themselves.
        DB::table('cemeteries')->whereIn('slug', $slugs)->update([
            'latitude' => null,
            'longitude' => null,
            'google_maps_url' => null,
            'primary_photo_path' => null,
            'price_min' => null,
            'price_max' => null,
            'price_source' => null,
            'price_effective_at' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * A real, working Google Maps text-search URL for the given fictional
     * cemetery name/city — see this migration's own class-level doc block
     * ("`google_maps_url` — a real, honest search link") for why this is
     * the honest choice over a fabricated precise pin-drop link.
     */
    private function mapsSearchUrl(string $name, string $city): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.urlencode("{$name}, {$city}, Indonesia");
    }
};
