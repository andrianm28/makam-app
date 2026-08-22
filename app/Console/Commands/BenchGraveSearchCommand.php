<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
 */
final class BenchGraveSearchCommand extends Command
{
    protected $signature = 'bench:grave-search
        {--iterations=200 : Number of search calls to time}
        {--fail-threshold-ms=500 : p95 threshold in milliseconds; exceeding it fails the command}';

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
