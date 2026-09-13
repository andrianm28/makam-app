<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-does the photo backfill that
 * `2026_09_08_100000_backfill_real_photos_for_real_and_example_cemeteries.php`
 * was meant to do, keyed on what a row CURRENTLY SHOWS instead of on what
 * its slug happens to be.
 *
 * ---------------------------------------------------------------------------
 * Why a second migration rather than fixing the first
 * ---------------------------------------------------------------------------
 * The 08 Sep migration matches two hardcoded slug lists — four real named
 * cemeteries (`tpu-karet-bivak`, ...) and `CemeteryExampleData::slugs()`
 * (`tpu-jakarta-1`, ...). On the dev environment NEITHER list matches
 * anything: its `cemeteries` rows came from a different seeder and carry
 * descriptive slugs (`tpu-bekasi-jatiasih`, `tps-bogor-cimanggu`, ...). The
 * intersection is empty, so every `->where('slug', $slug)->update(...)`
 * matched zero rows, `php artisan migrate` printed `DONE`, and not one
 * photo was attached. Confirmed by reading the live dev database on
 * 13 Sep 2026, after that migration had run: all ten rows still pointed at
 * `images/cemeteries/illustration-*.svg` while the four real `.jpg` files
 * sat unused in the image.
 *
 * The first migration is NOT edited. A migration that has already run in an
 * environment is a historical record; rewriting its `up()` would change what
 * a past migration means without changing what it did. This one runs after
 * it and finishes the job.
 *
 * ---------------------------------------------------------------------------
 * Keyed on value, not on identity — and why that is the right key here
 * ---------------------------------------------------------------------------
 * The product instruction was "add photos to every record that has a photo",
 * not "add photos to these named records". A slug list encodes an assumption
 * about WHICH rows exist, and that assumption is exactly what was false. The
 * predicate below encodes the instruction instead: a row is a target when it
 * is still showing a generic illustration, or showing nothing at all.
 *
 * A row that already carries a real photograph is never touched, whatever
 * its slug is — so an operator who uploads a genuine photograph of a genuine
 * cemetery does not have it overwritten by a stock image on the next deploy.
 *
 * ---------------------------------------------------------------------------
 * The photo list is frozen here on purpose
 * ---------------------------------------------------------------------------
 * `PHOTOS` duplicates `CemeteryExampleData::EXAMPLE_PHOTOS` by value. That is
 * deliberate and is the one case `AGENTS.md` §Documentation's
 * no-duplicate-canonical-data rule should not be read to forbid: a migration
 * must keep meaning the same thing forever, and a migration that reads a
 * mutable application constant silently changes what a past migration did
 * whenever that constant is edited. The constant is the canonical source for
 * APPLICATION code; this frozen copy is the record of what this migration
 * wrote on the day it ran.
 *
 * ---------------------------------------------------------------------------
 * Honesty framing, unchanged
 * ---------------------------------------------------------------------------
 * These are photographs of unrelated real cemeteries shown against rows that
 * are, for the ten seeded ones, fictional. That reversal of the
 * "illustrations, not photographs" stance was made on explicit product-owner
 * instruction and is documented at length in `CemeteryExampleData`'s own doc
 * block and in the 08 Sep migration. This migration widens WHICH rows get a
 * photo; it does not re-open that decision.
 */
return new class extends Migration
{
    /**
     * The four real stock photographs, frozen — see the doc block above for
     * why this is a deliberate copy of `CemeteryExampleData::EXAMPLE_PHOTOS`
     * rather than a reference to it.
     */
    private const array PHOTOS = [
        'images/cemeteries/photo-01-jakarta-memorial.jpg',
        'images/cemeteries/photo-02-grid-colorful.jpg',
        'images/cemeteries/photo-03-river-divide.jpg',
        'images/cemeteries/photo-04-red-green-grid.jpg',
    ];

    private const string ILLUSTRATION_PREFIX = 'images/cemeteries/illustration-';

    public function up(): void
    {
        // Ordered by slug so the round-robin assignment is deterministic:
        // the same database always produces the same photo for the same row,
        // whatever order the rows were inserted in. Without this, re-running
        // against a restored dump could shuffle which cemetery shows which
        // photograph for no reason a reader could explain.
        $slugs = DB::table('cemeteries')
            ->where(function ($query): void {
                $query->whereNull('primary_photo_path')
                    ->orWhere('primary_photo_path', 'like', self::ILLUSTRATION_PREFIX.'%');
            })
            ->orderBy('slug')
            ->pluck('slug');

        foreach ($slugs as $index => $slug) {
            DB::table('cemeteries')
                ->where('slug', $slug)
                ->update([
                    'primary_photo_path' => self::PHOTOS[$index % count(self::PHOTOS)],
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Deliberately a no-op, and this is the honest shape rather than a
     * convenient one.
     *
     * A faithful reverse is not possible: the rows this migration touched
     * were in TWO different states beforehand — some carried an
     * `illustration-*.svg`, some carried NULL — and nothing records which was
     * which. Writing illustrations back to all of them would invent a past
     * that did not exist for the NULL rows; writing NULL to all of them would
     * destroy the illustrations the others legitimately had.
     *
     * Rolling this back therefore means restoring from a backup, not running
     * `migrate:rollback`. Nothing is lost by the no-op: re-running `up()`
     * after a manual correction is safe, because the predicate skips any row
     * that already carries a real photograph.
     */
    public function down(): void
    {
        // Intentionally empty — see the doc block above.
    }
};
