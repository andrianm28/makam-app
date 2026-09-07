<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\GraveRegistry;

use App\Domain\GraveRegistry\GraveRecordProjection;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Domain\GraveRegistry\Models\GraveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * The genuinely PostgreSQL-only half of AC3's "fuzzy search" — the
 * `pg_trgm` extension, its GIN index, and the `similarity()` comparison
 * `App\Domain\GraveRegistry\GraveRegistryPublicQuery` runs only on that
 * driver.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE SKIPS ENTIRELY ON THE LOCAL DEFAULT DRIVER
 * ---------------------------------------------------------------------------
 * `phpunit.xml` sets `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`;
 * `.github/workflows/ci.yml` overrides to `pgsql` against a `postgres:18`
 * service. SQLite has no `pg_trgm` and no `similarity()`, so a local run
 * reports these as skipped, not passed. `makam-verify` states the
 * consequence plainly and it is repeated here because it is easy to be
 * misled by: **a green local run is not evidence that typo-tolerant search
 * works.** CI is the oracle. Four existing files use this same guard
 * (`AuditRecordTest` for the CHECK constraint, and the three Outbox tests
 * for `SELECT ... FOR UPDATE SKIP LOCKED`).
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT PROVE — AC4
 * ---------------------------------------------------------------------------
 * AC4 ("return search results in under 500 ms at 100,000 records") is
 * **NOT TESTED** here or anywhere in this batch. Nothing below measures
 * time and nothing below loads more than the sixteen seeded fixture rows.
 * `docs/planning/sprint-plan.md` §9 defers this spec's performance
 * certification to Sprint 13, and `docs/operations/release-gates.md` §I
 * forbids citing this 2 vCPU host as performance evidence in any case.
 * The index existing is not the target being met.
 */
final class GraveRecordTrigramSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'pg_trgm and similarity() are PostgreSQL-only; phpunit.xml defaults to SQLite locally. CI runs these against postgres:18.'
            );
        }
    }

    public function test_the_pg_trgm_extension_is_installed_by_the_migration(): void
    {
        $installed = DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'pg_trgm'");
        $this->assertNotNull(
            $installed,
            'pg_trgm must exist; 2026_08_08_100000_create_grave_records_table.php creates it.'
        );
    }

    public function test_the_gin_trigram_index_exists_on_the_normalized_name_column(): void
    {
        $index = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'grave_records' AND indexname = 'grave_records_name_trgm_idx'"
        );

        $this->assertNotNull($index, 'The GIN trigram index must exist on grave_records.');
        $this->assertStringContainsString('gin', strtolower((string) $index->indexdef));
        $this->assertStringContainsString('gin_trgm_ops', strtolower((string) $index->indexdef));
        $this->assertStringContainsString('deceased_name_normalized', strtolower((string) $index->indexdef));
    }

    /**
     * The behaviour a plain `LIKE` cannot deliver, and the whole reason
     * AC3 says "fuzzy": a misspelling still finds the record. "Santosa"
     * for "Santoso" is the single most ordinary way an Indonesian family
     * name gets mistyped.
     *
     * The searched record is created IN this test rather than taken from
     * the seed: the seeded names are generator-derived ("Contoh Sejahtera
     * 1"-style), and a similarity test needs a fixed name pair it owns, not
     * a generated value it would otherwise be pinning.
     */
    public function test_a_misspelled_name_still_finds_the_record(): void
    {
        GraveRecord::factory()->create([
            'cemetery_id' => CemeteryFixture::id('package', 0),
            'deceased_name' => 'Contoh Budi Santoso',
        ]);

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id('package', 0),
            name: 'Budi Santosa',
        ));

        $names = array_map(
            static fn (GraveRecordProjection $row): ?string => $row->deceasedName,
            $outcome->openResults
        );

        $this->assertContains(
            'Contoh Budi Santoso',
            $names,
            'A one-letter misspelling must still match — this is what similarity() buys over LIKE.'
        );
    }

    /**
     * The threshold must actually exclude something, or "fuzzy" would mean
     * "matches everything" and the privacy surface would be far wider than
     * AC14 assumes.
     */
    public function test_an_unrelated_name_does_not_match(): void
    {
        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id('package', 0),
            name: 'Qxzvwk Mnbvcx',
        ));

        $this->assertSame(0, $outcome->matchCount());
        $this->assertTrue($outcome->isNoResult());
    }

    /**
     * A short exact substring inside a longer stored name scores LOW on
     * trigram similarity, so similarity alone would rank it out. This is
     * why `buildQuery()` uses `LIKE ... OR similarity(...)` rather than
     * similarity by itself — the most obvious thing a family types must
     * never be excluded by a threshold tuned for typo tolerance.
     */
    public function test_a_short_exact_substring_still_matches_despite_a_low_similarity_score(): void
    {
        GraveRecord::factory()->create([
            'cemetery_id' => CemeteryFixture::id('package', 0),
            'deceased_name' => 'Contoh Budi Santoso',
        ]);

        $normalized = 'budi';

        $score = DB::selectOne(
            'SELECT similarity(deceased_name_normalized, ?) AS score FROM grave_records WHERE deceased_name = ?',
            [$normalized, 'Contoh Budi Santoso']
        );

        $this->assertNotNull($score);
        $this->assertLessThan(
            GraveRegistryPublicQuery::SIMILARITY_THRESHOLD,
            (float) $score->score,
            'This test is only meaningful while the substring scores BELOW the threshold; retune it if pg_trgm changes.'
        );

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id('package', 0),
            name: 'Budi',
        ));

        $names = array_map(
            static fn (GraveRecordProjection $row): ?string => $row->deceasedName,
            $outcome->openResults
        );

        $this->assertContains('Contoh Budi Santoso', $names);
    }

    // =========================================================================
    // PERF-13 (Phase 3 Batch M7a)
    // =========================================================================

    /**
     * `2026_09_07_100100_add_grave_records_name_trgm_gist_index.php` adds a
     * SECOND trigram index — GiST, not GIN — specifically so `ORDER BY
     * deceased_name_normalized <-> ?` (the KNN distance operator) can be
     * served by an index scan. GIN has no ordering support at all (see that
     * migration's own doc block and `2026_08_08_100000_create_grave_records_
     * table.php`'s), so this index is additive, not a replacement for the
     * existing GIN one asserted above.
     */
    public function test_the_gist_trigram_index_exists_on_the_normalized_name_column(): void
    {
        $index = DB::selectOne(
            "SELECT indexdef FROM pg_indexes WHERE tablename = 'grave_records' AND indexname = 'grave_records_name_trgm_gist_idx'"
        );

        $this->assertNotNull($index, 'The GiST trigram index must exist on grave_records.');
        $this->assertStringContainsString('gist', strtolower((string) $index->indexdef));
        $this->assertStringContainsString('gist_trgm_ops', strtolower((string) $index->indexdef));
        $this->assertStringContainsString('deceased_name_normalized', strtolower((string) $index->indexdef));
    }

    /**
     * The heart of PERF-13: before this fix, the disjunction was `LIKE
     * '%...%' OR similarity(deceased_name_normalized, ?) >= threshold`
     * (a bare function call, not an indexed operator) and the ORDER BY used
     * `similarity(...) DESC`. Neither half could use an index — see
     * `GraveRegistryPublicQuery::buildQuery()`'s own PERF-13 comment.
     *
     * This proves the REWRITTEN query shape (`column % ?` for the WHERE
     * clause, `column <-> ?` for the ORDER BY) actually gets an index plan
     * from the real Postgres planner, with sequential scans disabled to
     * make the assertion meaningful at this test database's small scale —
     * see `AuditEventsTableIndexUsageTest`'s equivalent test for the same
     * technique and its own reasoning.
     */
    public function test_explain_shows_index_scans_for_the_rewritten_fuzzy_match_query(): void
    {
        GraveRecord::factory()->count(20)->create([
            'cemetery_id' => CemeteryFixture::id('package', 0),
        ]);

        DB::statement('ANALYZE grave_records');
        DB::statement('SET LOCAL enable_seqscan = off');
        DB::statement('SET pg_trgm.similarity_threshold = '.GraveRegistryPublicQuery::SIMILARITY_THRESHOLD);

        $plan = collect(DB::select(
            'EXPLAIN SELECT * FROM grave_records '.
            'WHERE cemetery_id = ? AND (deceased_name_normalized LIKE ? OR deceased_name_normalized % ?) '.
            'ORDER BY deceased_name_normalized <-> ?, deceased_name_normalized LIMIT 50',
            [CemeteryFixture::id('package', 0), '%budi%', 'budi', 'budi']
        ))->map(fn ($row) => $row->{'QUERY PLAN'})->implode("\n");

        $this->assertStringContainsString(
            'Index',
            $plan,
            "Expected an index scan to be available for the rewritten query shape, got:\n{$plan}",
        );
    }

    /**
     * The rewrite must not change WHAT matches, only how the database gets
     * there. `similarity(a, b) >= threshold` and the operator form `a % b`
     * are documented by pg_trgm as equivalent once `pg_trgm.similarity_
     * threshold` is set to that same threshold — this proves it end to end
     * through the real public search path, not just by reading pg_trgm's
     * docs.
     */
    public function test_the_rewritten_operator_form_still_finds_a_misspelled_name(): void
    {
        GraveRecord::factory()->create([
            'cemetery_id' => CemeteryFixture::id('package', 0),
            'deceased_name' => 'Contoh Budi Santoso',
        ]);

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id('package', 0),
            name: 'Budi Santosa',
        ));

        $names = array_map(
            static fn (GraveRecordProjection $row): ?string => $row->deceasedName,
            $outcome->openResults
        );

        $this->assertContains('Contoh Budi Santoso', $names);
    }
}
