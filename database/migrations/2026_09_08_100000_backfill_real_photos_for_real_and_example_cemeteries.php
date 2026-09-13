<?php

declare(strict_types=1);

use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces `primary_photo_path` on EVERY cemetery row this codebase seeds
 * — the four REAL, named cemeteries
 * (`2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php`'s
 * `SLUGS_IN_ORDER`) and the ten fictional example rows
 * (`App\Support\ExampleData\CemeteryExampleData`) — with real, neutral
 * cemetery/garden stock photography, cycled from the same four-file pool
 * both those places already used for the four generic illustration SVGs
 * this migration retires.
 *
 * ---------------------------------------------------------------------------
 * Why this reverses two earlier, deliberate "illustrations only" decisions
 * ---------------------------------------------------------------------------
 * Both `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_
 * cemeteries.php` (fictional rows — fabrication risk: inventing a photo of
 * a cemetery that does not exist) and `2026_08_24_100000_backfill_photo_
 * and_maps_url_for_real_cemeteries.php` (the four real, named rows —
 * misattribution risk: showing a photo of a DIFFERENT real cemetery under
 * a specific real one's name) chose generic SVG illustrations specifically
 * to avoid those two risks. Product owner direction, 8 Sep 2026 (relayed
 * via WhatsApp): show real photography on every card regardless. This
 * migration accepts both risks explicitly, on direct instruction, rather
 * than resolving them — none of the four photos below depicts any of these
 * fourteen specific cemeteries; they are real photos of OTHER real
 * cemeteries, reused across many rows the same way the four SVGs were.
 *
 * `App\Support\ExampleData\CemeteryExampleData::EXAMPLE_PHOTOS` was updated
 * in the same change to point at these four files (see that class's own
 * doc block), so `applyBackfill()` below now writes photo paths, not SVG
 * paths, for the ten fictional rows — everything else that method sets
 * (price, `updated_at`) is unchanged and idempotent to re-run.
 *
 * The four photo files themselves live at
 * `public/images/cemeteries/photo-0{1,2,3,4}-*.jpg` (real, daylight,
 * no people, no religious iconography — design-system.md §2.2's imagery
 * constraints), sized ~960px wide and under GATE 14's 300KB general
 * image-weight budget.
 */
return new class extends Migration
{
    private const array PHOTOS = [
        'images/cemeteries/photo-01-jakarta-memorial.jpg',
        'images/cemeteries/photo-02-grid-colorful.jpg',
        'images/cemeteries/photo-03-river-divide.jpg',
        'images/cemeteries/photo-04-red-green-grid.jpg',
    ];

    /**
     * The four generic illustration SVGs both superseded migrations used —
     * kept here only so `down()` can restore the exact pre-migration state.
     */
    private const array ILLUSTRATIONS = [
        'images/cemeteries/illustration-01-gate.svg',
        'images/cemeteries/illustration-02-grove.svg',
        'images/cemeteries/illustration-03-path.svg',
        'images/cemeteries/illustration-04-garden.svg',
    ];

    private const array REAL_CEMETERY_SLUGS_IN_ORDER = [
        'tpu-karet-bivak',
        'tpu-petamburan',
        'tpu-pondok-kelapa',
        'tpu-semper-budi-dharma',
    ];

    public function up(): void
    {
        foreach (self::REAL_CEMETERY_SLUGS_IN_ORDER as $index => $slug) {
            DB::table('cemeteries')
                ->where('slug', $slug)
                ->update([
                    'primary_photo_path' => self::PHOTOS[$index % count(self::PHOTOS)],
                    'updated_at' => now(),
                ]);
        }

        // Re-runs the (idempotent) example-data photo/price backfill so
        // rows already seeded on this environment pick up the new photo
        // paths from the updated EXAMPLE_PHOTOS constant, not just fresh
        // seeds going forward.
        CemeteryExampleData::applyBackfill();
    }

    public function down(): void
    {
        // Restores the exact round-robin illustration each row had before
        // this migration, not just a single fixed SVG — same index-based
        // cycling both superseded migrations originally used.
        foreach (self::REAL_CEMETERY_SLUGS_IN_ORDER as $index => $slug) {
            DB::table('cemeteries')
                ->where('slug', $slug)
                ->update([
                    'primary_photo_path' => self::ILLUSTRATIONS[$index % count(self::ILLUSTRATIONS)],
                    'updated_at' => now(),
                ]);
        }

        foreach (CemeteryExampleData::slugs() as $index => $slug) {
            DB::table('cemeteries')
                ->where('slug', $slug)
                ->update([
                    'primary_photo_path' => self::ILLUSTRATIONS[$index % count(self::ILLUSTRATIONS)],
                    'updated_at' => now(),
                ]);
        }
    }
};
