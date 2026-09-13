<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan bench:grave-search` — the real, executed AC4 certification
 * (`docs/operations/performance-and-capacity.md` §2: "Grave fuzzy search:
 * below 500ms at 100,000 records"). Measures
 * `GraveRegistryPublicQuery::search()`'s own wall-clock latency directly,
 * across real cemeteries/terms already in the database (run
 * `bench:generate-grave-dataset` first for a real 100k-record certification
 * run) — see this plan's own Context section for why a direct query
 * benchmark, not a k6/HTTP benchmark, is the right tool for this
 * specific, non-concurrent latency target.
 *
 * Picks a real search term from an actual row in the LARGEST cemetery by
 * record count (the worst case for the residual LIKE/similarity() filter
 * described in `2026_08_08_100000_create_grave_records_table.php`'s own
 * doc block), not a synthetic term — this measures the real query shape
 * the app runs, including cache-cold Postgres query planning on the first
 * iteration.
 *
 * The measured numbers are only as representative as the corpus behind
 * them: `bench:generate-grave-dataset` derives each row's name/typo
 * components via a CRC32 hash of the row index (`GenerateGraveRegistry
 * LoadDatasetCommand::mixedIndex()`), specifically so a single cemetery's
 * rows span dozens of distinct `deceased_name` values instead of the
 * handful a naive `$i % N` derivation collapses to — this command's own
 * timing reflects that real name-cardinality, not a degenerate corpus a
 * query planner or cache could exploit.
 */
final class BenchGraveSearchCommand extends Command
{
    protected $signature = 'bench:grave-search
        {--iterations=200 : Number of search calls to time}
        {--fail-threshold-ms=500 : p95 threshold in milliseconds; exceeding it fails the command}
        {--explain : Also print EXPLAIN (ANALYZE, BUFFERS) for the exact fuzzy-match query shape — PostgreSQL only. PERF-13 evidence: confirms the rewritten query actually uses the trigram indexes instead of asserting it from reading the SQL.}';

    protected $description = 'Measure GraveRegistryPublicQuery::search() p50/p95/p99 against the current database (AC4 certification).';

    public function handle(): int
    {
        $iterations = (int) $this->option('iterations');
        $thresholdMs = (int) $this->option('fail-threshold-ms');

        $largestCemetery = DB::table('grave_records')
            ->select('cemetery_id', DB::raw('count(*) as record_count'))
            ->groupBy('cemetery_id')
            ->orderByDesc('record_count')
            ->first();

        if ($largestCemetery === null) {
            $this->error('No grave_records rows found — run `php artisan bench:generate-grave-dataset` first.');

            return self::FAILURE;
        }

        $sampleRecord = DB::table('grave_records')
            ->where('cemetery_id', $largestCemetery->cemetery_id)
            ->first();

        $searchTerm = mb_substr((string) $sampleRecord->deceased_name, 0, 4);

        $this->info(sprintf(
            'Benchmarking against cemetery %s (%d records), search term "%s", %d iterations...',
            $largestCemetery->cemetery_id,
            $largestCemetery->record_count,
            $searchTerm,
            $iterations,
        ));

        $timingsMs = [];

        for ($i = 0; $i < $iterations; $i++) {
            $criteria = GraveSearchCriteria::make(
                cemeteryId: (string) $largestCemetery->cemetery_id,
                name: $searchTerm,
                block: '',
                deathDate: '',
            );

            $start = hrtime(true);
            GraveRegistryPublicQuery::search($criteria);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $timingsMs[] = $elapsedMs;
        }

        if ($this->option('explain') && DB::connection()->getDriverName() === 'pgsql') {
            $this->printExplain((string) $largestCemetery->cemetery_id, $searchTerm);
        }

        sort($timingsMs);

        $p50 = $this->percentile($timingsMs, 50);
        $p95 = $this->percentile($timingsMs, 95);
        $p99 = $this->percentile($timingsMs, 99);

        $this->table(
            ['Metric', 'Value (ms)'],
            [
                ['p50', number_format($p50, 2)],
                ['p95', number_format($p95, 2)],
                ['p99', number_format($p99, 2)],
                ['record count (largest cemetery)', (string) $largestCemetery->record_count],
                ['iterations', (string) $iterations],
            ]
        );

        if ($p95 > $thresholdMs) {
            $this->error(sprintf(
                'AC4 FAILED: p95 (%.2fms) exceeds the %dms target.',
                $p95,
                $thresholdMs,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('AC4 PASSED: p95 (%.2fms) is within the %dms target.', $p95, $thresholdMs));

        return self::SUCCESS;
    }

    /**
     * PERF-13 evidence: prints `EXPLAIN (ANALYZE, BUFFERS)` for the exact
     * WHERE/ORDER BY shape `GraveRegistryPublicQuery::buildQuery()` runs
     * once a name term is present, so a reviewer can see the planner
     * actually choosing `grave_records_name_trgm_idx` (GIN, for the `%`/
     * LIKE WHERE clause) and `grave_records_name_trgm_gist_idx` (GiST, for
     * the `<->` ORDER BY) rather than taking that on faith from reading the
     * SQL. Mirrors `buildQuery()`'s own predicate/order shape directly
     * (that method is `private`, so this rebuilds the equivalent SQL rather
     * than reflecting into it) — see `GraveRegistryPublicQuery::search()`
     * for the source of truth this must stay in sync with.
     */
    private function printExplain(string $cemeteryId, string $searchTerm): void
    {
        $normalizedName = GraveNameNormalizer::normalize($searchTerm);

        if ($normalizedName === '') {
            return;
        }

        DB::statement('SET pg_trgm.similarity_threshold = '.GraveRegistryPublicQuery::SIMILARITY_THRESHOLD);

        $like = '%'.$normalizedName.'%';

        $rows = DB::select(
            'EXPLAIN (ANALYZE, BUFFERS) '.
            'SELECT * FROM grave_records '.
            'WHERE cemetery_id = ? '.
            'AND (deceased_name_normalized LIKE ? OR deceased_name_normalized % ?) '.
            'ORDER BY deceased_name_normalized <-> ?, deceased_name_normalized '.
            'LIMIT 50',
            [$cemeteryId, $like, $normalizedName, $normalizedName]
        );

        $this->newLine();
        $this->info('EXPLAIN (ANALYZE, BUFFERS) for the fuzzy-match query shape:');

        foreach ($rows as $row) {
            $this->line((string) $row->{'QUERY PLAN'});
        }
    }

    /**
     * @param  list<float>  $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): float
    {
        if ($sortedValues === []) {
            return 0.0;
        }

        $index = (int) ceil(($percentile / 100) * count($sortedValues)) - 1;
        $index = max(0, min($index, count($sortedValues) - 1));

        return $sortedValues[$index];
    }
}
