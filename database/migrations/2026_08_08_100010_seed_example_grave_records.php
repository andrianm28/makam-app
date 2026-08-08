<?php

declare(strict_types=1);

use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * `2026_07_26_170400_seed_faq_categories_and_articles.php` documents this
 * in full: nothing in CI, the Dockerfile, or any deployment script runs
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
 *   - `TPU Jakarta Menteng`  — mixed: open rows AND one `limited` row, so a
 *     search can return readable results while still honestly reporting a
 *     match it cannot show.
 *   - `TPS Jakarta Kemang`   — ALL restricted (one `limited`, one
 *     `closed`), so the *pure* privacy-limited state (matches exist, none
 *     readable) is reachable. This is the state most at risk of being
 *     wrongly rendered as "not found", which is the defect this spec names.
 *   - `TPU Bogor Bantarjati`, `TPU Depok Sawangan`, `TPU Tangerang
 *     Cipondoh` — plain open rows.
 *   - `TPU Bekasi Jatiasih`  — one open, one `closed`.
 *   - `TPS Bekasi Harapan Indah` — one open row inside the DRAFT cemetery
 *     the cemetery seed deliberately left unpublished. A negative fixture:
 *     an unpublished cemetery must not become searchable just because a
 *     record points at it. `App\Domain\Renewal\RenewalLocationQuery::
 *     findPublishedCemetery()` is what stops it, and this row is what lets
 *     a test prove that rather than assume it.
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
        $now = now();

        // Shape: [cemetery slug, deceased name, block, death date, due date, access mode]
        $records = [
            // --- TPU Jakarta Menteng: mixed open + one limited ---
            ['tpu-jakarta-menteng', 'Contoh Budi Santoso', 'A-12', '2018-04-11', '2026-04-11', GraveRecordAccessMode::OPEN],
            ['tpu-jakarta-menteng', 'Contoh Siti Rahayu', 'A-15', '2019-09-02', '2027-09-02', GraveRecordAccessMode::OPEN],
            ['tpu-jakarta-menteng', 'Contoh Bambang Wijaya', 'B-03', '2020-01-27', '2026-01-27', GraveRecordAccessMode::OPEN],
            ['tpu-jakarta-menteng', 'Contoh Sri Handayani', 'B-08', '2021-06-18', '2027-06-18', GraveRecordAccessMode::LIMITED],

            // --- TPS Jakarta Kemang: every row restricted (see doc block) ---
            ['tps-jakarta-kemang', 'Contoh Agus Priyono', 'C-01', '2017-11-30', '2026-11-30', GraveRecordAccessMode::LIMITED],
            ['tps-jakarta-kemang', 'Contoh Dewi Anggraini', 'C-04', '2022-02-14', '2028-02-14', GraveRecordAccessMode::CLOSED],

            // --- TPU Bogor Bantarjati ---
            ['tpu-bogor-bantarjati', 'Contoh Joko Purnomo', 'D-07', '2016-08-05', '2026-08-05', GraveRecordAccessMode::OPEN],
            ['tpu-bogor-bantarjati', 'Contoh Rina Marlina', 'D-09', '2020-12-21', '2026-12-21', GraveRecordAccessMode::OPEN],

            // --- TPU Depok Sawangan ---
            // Deliberately missing a death date: the registry incompleteness
            // AC5's empty-state copy tells the public about must be real in
            // the data, not only in the copy.
            ['tpu-depok-sawangan', 'Contoh Hendra Gunawan', 'E-02', null, '2027-03-15', GraveRecordAccessMode::OPEN],
            ['tpu-depok-sawangan', 'Contoh Lestari Wulandari', 'E-05', '2019-05-09', null, GraveRecordAccessMode::OPEN],

            // --- TPU Tangerang Cipondoh ---
            ['tpu-tangerang-cipondoh', 'Contoh Andi Kurniawan', 'F-11', '2021-10-03', '2027-10-03', GraveRecordAccessMode::OPEN],

            // --- TPU Bekasi Jatiasih ---
            ['tpu-bekasi-jatiasih', 'Contoh Yusuf Maulana', 'G-06', '2018-07-22', '2026-07-22', GraveRecordAccessMode::OPEN],
            ['tpu-bekasi-jatiasih', 'Contoh Nurul Hasanah', 'G-10', '2023-01-08', '2029-01-08', GraveRecordAccessMode::CLOSED],

            // --- TPS Bekasi Harapan Indah: the DRAFT cemetery (negative fixture) ---
            ['tps-bekasi-harapan-indah', 'Contoh Rahmat Hidayat', 'H-01', '2020-03-30', '2026-03-30', GraveRecordAccessMode::OPEN],
        ];

        $cemeteryIds = DB::table('cemeteries')
            ->whereIn('slug', array_unique(array_column($records, 0)))
            ->pluck('id', 'slug');

        foreach ($records as [$slug, $name, $block, $deathDate, $dueDate, $accessMode]) {
            $cemeteryId = $cemeteryIds[$slug] ?? null;

            // A cemetery slug this migration expects but cannot find means
            // the cemetery seed was rolled back or edited. Skip rather than
            // fail: a missing FIXTURE row must never block a real
            // deployment's migration run, and the skipped row is a fixture
            // by definition. Real (non-`contoh`) data would warrant the
            // opposite choice.
            if ($cemeteryId === null) {
                continue;
            }

            DB::table('grave_records')->insert([
                'id' => (string) Str::uuid(),
                'cemetery_id' => $cemeteryId,
                'deceased_name' => $name,
                // GraveRecord::booted() is what normally derives this, but
                // this migration writes through the query builder (the
                // established shape for every seed migration here), which
                // does not fire model events. Calling the same normalizer
                // the model calls keeps the stored form identical to what
                // GraveRegistryPublicQuery searches against — see
                // GraveNameNormalizer's doc block for why that symmetry is
                // load-bearing rather than cosmetic.
                'deceased_name_normalized' => GraveNameNormalizer::normalize($name),
                'block' => $block,
                'death_date' => $deathDate,
                'due_date' => $dueDate,
                // Never populated for a seeded row. See the table
                // migration's doc block: no encrypted-contact storage
                // exists yet (platform DocumentVault is Sprint 6), and
                // inventing a phone number for a fictional heir would be
                // fabricated personal data, not a placeholder.
                'heir_contact_reference' => null,
                'access_mode' => $accessMode,
                'source' => GraveRecordSource::CONTOH,
                // Provenance timestamp of the fixture itself. Honest: this
                // is genuinely when this "source" was last updated.
                'source_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
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
        $names = [
            'Contoh Budi Santoso',
            'Contoh Siti Rahayu',
            'Contoh Bambang Wijaya',
            'Contoh Sri Handayani',
            'Contoh Agus Priyono',
            'Contoh Dewi Anggraini',
            'Contoh Joko Purnomo',
            'Contoh Rina Marlina',
            'Contoh Hendra Gunawan',
            'Contoh Lestari Wulandari',
            'Contoh Andi Kurniawan',
            'Contoh Yusuf Maulana',
            'Contoh Nurul Hasanah',
            'Contoh Rahmat Hidayat',
        ];

        DB::table('grave_records')
            ->where('source', GraveRecordSource::CONTOH)
            ->whereIn('deceased_name', $names)
            ->delete();
    }
};
