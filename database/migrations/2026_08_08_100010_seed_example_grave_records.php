<?php

declare(strict_types=1);

use App\Domain\GraveRegistry\GraveRecordSource;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ===========================================================================
 * THIS IS DUMMY / PLACEHOLDER DATA. NONE OF THE FOLLOWING IS REAL.
 * ===========================================================================
 * Every row below is a FICTIONAL grave record describing a person who does
 * not exist, in a fictional cemetery seeded by
 * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`. None of
 * the following is real:
 *
 *   - no deceased person named below has ever existed;
 *   - no block identifier below refers to a real plot;
 *   - no death date or due date below describes a real event or a real
 *     financial obligation;
 *   - no heir contact is stored for any row (`heir_contact_reference` is
 *     `null` on all of them, deliberately — see the table migration).
 *
 * Every `deceased_name` begins with the literal word **"Contoh"**
 * (Indonesian for "Example"), which is this repository's established
 * fabricated-data marker — the same convention as "Jl. Contoh ..." in the
 * cemetery seed and "Estimasi internal (data contoh)" in the price
 * backfill. The marker is on the NAME itself, not only in a doc comment,
 * for a reason specific to this table: an address that looks fake is a
 * cosmetic problem, whereas a fabricated but plausible-looking name on a
 * grave registry could be mistaken for a real deceased person by anyone who
 * queries this database without reading the migration. Prefixing the name
 * makes that impossible at a glance and guarantees no full string here can
 * collide with a real person's full name.
 *
 * The given/family names after the marker are among the most common in
 * Indonesia and are deliberately not those of any notable person.
 * `source` is `GraveRecordSource::CONTOH` on every row, so a surface can
 * detect and label example data programmatically rather than by matching on
 * the name text.
 *
 * A later batch working from a real operator registry replaces this
 * migration's rows wholesale. This exists so `dev.makam.co.id` can render
 * the renewal journey end to end — `docs/operations/dev-staging-
 * environment.md` §4 names synthetic data the correct content type there.
 *
 * ---------------------------------------------------------------------------
 * Why a data migration and not `database/seeders/`
 * ---------------------------------------------------------------------------
 * The fourteen rows are DEFINED in
 * `App\Support\ExampleData\CemeteryExampleData::graveRecords()` and
 * materialized here by `CemeteryExampleData::seedGraveRecords()`. This
 * migration remains the delivery path for the same reason
 * `2026_07_26_170400_seed_faq_categories_and_articles.php` documents in
 * full: nothing in CI, the Dockerfile, or any deployment script runs
 * `php artisan db:seed`, so a seeder class would never execute. Every
 * catalogue and master-data set in this repository ships as a timestamped
 * data migration.
 *
 * Migration timestamp slot: `2026_08_08_100000`-`2026_08_08_100099`,
 * assigned to Sprint 4 task S4-T7 before the batch fanned out.
 *
 * ---------------------------------------------------------------------------
 * The access-mode spread is a FIXTURE DESIGN, not arbitrary
 * ---------------------------------------------------------------------------
 * requirements.md AC14's three modes each need to be reachable from seeded
 * data alone, because `makam-testing` forbids domain factories — tests
 * assert against real seeded rows and real Actions, never fabricated
 * fixtures. So the rows below are arranged to make all of
 * `.kiro/specs/renewal-and-grave-registry/tasks.md`'s required states
 * reachable without any test inventing data:
 *
 *   - `TPS Jakarta 2` (index 1, `CemeteryExampleData::ALL_RESTRICTED_SLUG`)
 *     — ALL restricted (one `limited`, one `closed`), so the *pure*
 *     privacy-limited state (matches exist, none readable) is reachable.
 *     This is the state most at risk of being wrongly rendered as "not
 *     found", which is the defect this spec names.
 *   - `TPU Jakarta 1` (index 0) — two open rows, the second with a NULL due
 *     date, so an open search can still honestly surface registry
 *     incompleteness (AC5's empty-state copy) instead of a fake-perfect
 *     dataset.
 *   - the remaining published cemeteries (indices 2-8) — plain open rows,
 *     one or two per cemetery, from the same deterministic index math;
 *     `TPU Depok 5` (index 4) also carries a row with a NULL death date,
 *     the other incompleteness state.
 *   - `TPS Bekasi 10` (index 9, `CemeteryExampleData::DRAFT_SLUG`) — one
 *     open row inside the DRAFT cemetery the cemetery seed deliberately
 *     left unpublished. A negative fixture: an unpublished cemetery must
 *     not become searchable just because a record points at it.
 *     `App\Domain\CemeteryDirectory\CemeteryPublicQuery::findPublishedById()`
 *     is what stops it, and this row is what lets a test prove that rather
 *     than assume it.
 *
 * The exact rows are produced by the generator's pure index math — see
 * `CemeteryExampleData::graveRecords()`; this migration only materializes
 * them. `tests/Unit/Support/ExampleData/CemeteryExampleDataTest.php` locks
 * the role distribution against this design.
 *
 * The no-result state needs no fixture — it is any name that matches
 * nothing.
 *
 * Dates are fixed literals, never derived from `now()`: a due date computed
 * relative to migration time would make every assertion about it depend on
 * when the test database was built.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The fourteen example grave records are defined in
        // App\Support\ExampleData\CemeteryExampleData::graveRecords().
        // It resolves cemetery slug -> id at runtime and skips (never
        // fails on) a slug the cemetery seed did not produce, exactly like
        // the original inline loop.
        CemeteryExampleData::seedGraveRecords();
    }

    /**
     * Deletes exactly the rows `up()` inserted — the enumerated names,
     * further scoped to `source = contoh` so a later real-data batch's rows
     * can never be caught by this rollback even if one of them somehow
     * shared a name. Never a blanket truncate of `grave_records`
     * (`makam-migration`; `AGENTS.md` §Database).
     */
    public function down(): void
    {
        $names = array_column(CemeteryExampleData::graveRecords(), 1);

        DB::table('grave_records')
            ->where('source', GraveRecordSource::CONTOH)
            ->whereIn('deceased_name', $names)
            ->delete();
    }
};
