<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\GraveRegistry;

use App\Domain\GraveRegistry\GraveRecordProjection;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Support\ExampleData\CemeteryExampleData;
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
 * time and nothing below loads more than the fourteen seeded fixture rows.
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
     */
    public function test_a_misspelled_name_still_finds_the_record(): void
    {
        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]),
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
            cemeteryId: CemeteryFixture::id(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]),
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
            cemeteryId: CemeteryFixture::id(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]),
            name: 'Budi',
        ));

        $names = array_map(
            static fn (GraveRecordProjection $row): ?string => $row->deceasedName,
            $outcome->openResults
        );

        $this->assertContains('Contoh Budi Santoso', $names);
    }
}
