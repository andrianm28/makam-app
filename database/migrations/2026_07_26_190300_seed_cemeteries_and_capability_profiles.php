<?php

declare(strict_types=1);

use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds `cemeteries`, one current-version `cemetery_capability_profiles`
 * row per cemetery, and the `cemetery_packages` example rows — all of it
 * now delegated to `App\Support\ExampleData\CemeteryExampleData` (the ONE
 * place the example data is defined; see that class's doc block for the
 * honesty framing this migration used to carry).
 *
 * ---------------------------------------------------------------------------
 * Why this migration still exists, and why it was EDITED in place
 * ---------------------------------------------------------------------------
 * Nothing in CI or any deployment process runs `php artisan db:seed`
 * (verified in `.github/workflows/ci.yml`, the Dockerfile, the entrypoint,
 * and compose), so example content that must exist in every environment
 * ships through `php artisan migrate`. This migration remains that path;
 * only the inline data has moved out.
 *
 * Editing an already-applied data migration is normally forbidden in this
 * repository (see `2026_07_26_210000`'s own doc block). This is the one
 * deliberate exception: the generator reproduces the EXACT business columns
 * this migration used to insert (the suite locks them — `CemeterySeedTest`,
 * `CemeteryPackageAvailabilityTest`, `GraveRecordSeedTest`), environments
 * that already applied this file are skipped by `migrate:status` so their
 * rows are untouched, and the alternative (a rebuild migration that deletes
 * and reinserts against `grave_records`' RESTRICT FK) is destructive in
 * the way `AGENTS.md` §Database forbids. The de-hardcoding is a
 * representation refactor, not a data change.
 *
 * Migration timestamp slot: see
 * `2026_07_26_190000_create_cemeteries_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        CemeteryExampleData::seed();
    }

    public function down(): void
    {
        // `cemeteries.id` now has FOUR inbound foreign keys with THREE
        // different delete behaviours — `cemetery_capability_profiles` and
        // `cemetery_packages` cascade, `grave_records` RESTRICTs
        // (2026_08_08_100000:130-132), `booking_drafts` SET NULLs
        // (2026_08_08_130000:67). The grave_records RESTRICT is a row-level
        // DELETE constraint that fires on the statement below. A sequential
        // `migrate:rollback` still works because
        // `2026_08_08_100010_seed_example_grave_records.php`'s own `down()`
        // removes its fixture rows first; a targeted rollback of just this
        // migration does NOT. Do not extend this to delete `grave_records`
        // — that table is owned by `GraveRegistry`, and per `AGENTS.md`
        // §Database we do not rely on destructive production `down()`
        // migrations in the first place.
        DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->delete();
    }
};
