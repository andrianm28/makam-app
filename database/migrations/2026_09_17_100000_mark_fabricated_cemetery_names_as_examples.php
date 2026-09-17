<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Put the fabricated-data marker where a visitor actually reads it: on the
 * cemetery NAME.
 *
 * ---------------------------------------------------------------------------
 * What a visitor sees today
 * ---------------------------------------------------------------------------
 * Every seeded cemetery already carries a marker — in its ADDRESS, as
 * "Jl. Contoh ...", written by
 * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`. That marker
 * renders on the detail page (`directory/detail.blade.php:116`, where AC11
 * requires the address unconditionally) and nowhere else. The directory
 * listing, the homepage, the booking wizard's picker and the visitation page
 * all show the name and the city, and nothing more.
 *
 * So on the public beta a visitor browsing cemeteries reads "TPU Jakarta
 * Menteng, Jakarta" with no indication whatsoever that no such listing exists.
 * Measured 17 Sep 2026: ten of ten cemeteries on beta are fabricated, and
 * their names are plausible Jakarta-area place names.
 *
 * ---------------------------------------------------------------------------
 * Why the marker goes in the column and not in a presenter
 * ---------------------------------------------------------------------------
 * `MarketplacePresenter::VENDOR_MARKER` appends "(vendor contoh)" at render
 * time, and for vendors that is right: it appends unconditionally, because
 * every marketplace vendor is fabricated today.
 *
 * Cemeteries are the other case, and this repository has already decided it.
 * `2026_08_08_100010_seed_example_grave_records.php` puts "Contoh" in the
 * `deceased_name` COLUMN and says why: "an address that looks fake is a
 * cosmetic problem, whereas a fabricated but plausible-looking name ... could
 * be mistaken for a real deceased person by anyone who queries this database
 * without reading the migration."
 *
 * The same reasoning, the same class of harm. A cemetery name does not stay
 * inside the nineteen Blade expressions that render it — it flows into
 * notifications, certificates and order records, and a presenter marker
 * reaches none of those. The column reaches all of them.
 *
 * ---------------------------------------------------------------------------
 * Why the address literal is spelled out here rather than imported
 * ---------------------------------------------------------------------------
 * `PurgeExampleDataCommand` holds the same prefix in a constant, and
 * `AGENTS.md` §Documentation forbids duplicating canonical data. This is the
 * deliberate exception, for the reason that finding (DB-13) exists at all:
 * that command identified example rows by matching values a LIVE class
 * produced, the class changed on 13 Aug 2026, and every row seeded before it
 * became invisible.
 *
 * An applied migration is frozen history. It must keep meaning exactly what it
 * meant on the day it ran, which a constant somebody may edit next month
 * cannot promise. So the literal is written here, once, and deliberately not
 * imported.
 *
 * ---------------------------------------------------------------------------
 * Scope, and what it does NOT do
 * ---------------------------------------------------------------------------
 * Idempotent: a row whose name already contains "contoh" is skipped, so
 * running this twice does not produce "... (pemakaman contoh) (pemakaman
 * contoh)".
 *
 * It renames. It deletes nothing. On beta those ten cemeteries are referenced
 * by 107 booking drafts and, through them, 29 orders — deleting them would
 * `nullOnDelete` the drafts' `cemetery_id` and silently strip the provenance
 * of every one of those orders. Removing fabricated cemeteries is an operator
 * decision about that history; making them honest is not, and does not wait
 * for it.
 *
 * On an environment with no fabricated rows — production, after a real purge —
 * this updates nothing and is a no-op.
 */
return new class extends Migration
{
    private const FABRICATED_ADDRESS_PREFIX = 'Jl. Contoh';

    private const MARKER = '(pemakaman contoh)';

    public function up(): void
    {
        DB::table('cemeteries')
            ->where('address', 'like', self::FABRICATED_ADDRESS_PREFIX.'%')
            ->where('name', 'not like', '%contoh%')
            ->update([
                'name' => DB::raw("name || ' ".self::MARKER."'"),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Reversible: strip the exact suffix this migration appended, and only
        // from rows that carry the address marker. A name that ends in
        // "(pemakaman contoh)" without the fabricated address was not written
        // here and is left alone.
        DB::table('cemeteries')
            ->where('address', 'like', self::FABRICATED_ADDRESS_PREFIX.'%')
            ->where('name', 'like', '% '.self::MARKER)
            ->update([
                'name' => DB::raw('left(name, length(name) - '.(strlen(self::MARKER) + 1).')'),
                'updated_at' => now(),
            ]);
    }
};
