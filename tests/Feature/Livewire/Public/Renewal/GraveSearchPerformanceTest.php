<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Console\Commands\GenerateGraveRegistryLoadDatasetCommand;
use App\Livewire\Public\Renewal\GraveSearch;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Request-level companion to `App\Console\Commands\BenchGraveSearchCommand`
 * (`docs/operations/performance-and-capacity.md` §2 AC4: "below 500ms at
 * 100,000 records"), which certifies `GraveRegistryPublicQuery::search()`'s
 * own wall-clock cost directly (p95 7.19ms at 100k records) but not the
 * full HTTP/Livewire request cycle a real renewal user experiences —
 * `App\Livewire\Public\Renewal\GraveSearch::render()` is what actually
 * calls that query from a live request.
 *
 * SCALE NOTE (see this suite's plan, Task 3): this test seeds ONE
 * cemetery with 5,000 records (~5% of the certified 100k target), not a
 * full-scale dataset — the raw query cost at 100k is already proven
 * near-flat by BenchGraveSearchCommand, so what this test adds is proof
 * that Livewire/HTTP-layer overhead does not erase that margin, which
 * does not require 100k rows to demonstrate. This is a request-level
 * smoke/regression proof, not a full AC4 recertification.
 */
final class GraveSearchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function openTheDataGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
    }

    public function test_a_full_search_request_completes_within_the_500ms_budget_at_a_representative_scale(): void
    {
        $this->openTheDataGate();

        Artisan::call(GenerateGraveRegistryLoadDatasetCommand::class, [
            '--cemeteries' => 1,
            '--records' => 5000,
        ]);

        $cemeteryId = DB::table('cemeteries')
            ->where('name', 'like', 'Contoh TPU Beban %')
            ->value('id');
        self::assertNotNull($cemeteryId, 'The benchmark dataset generator did not create a cemetery.');

        $sampleName = DB::table('grave_records')
            ->where('cemetery_id', $cemeteryId)
            ->value('deceased_name');
        self::assertNotNull($sampleName, 'The benchmark dataset generator did not create any grave records.');
        $searchTerm = mb_substr((string) $sampleName, 0, 4);

        $start = microtime(true);

        Livewire::withQueryParams(['tpu' => $cemeteryId])
            ->test(GraveSearch::class)
            ->set('name', $searchTerm)
            ->call('search')
            ->assertSet('searched', true);

        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(
            500.0,
            $elapsedMs,
            sprintf('Full request-level grave search took %.2fms, over the 500ms AC4 budget.', $elapsedMs)
        );
    }
}
