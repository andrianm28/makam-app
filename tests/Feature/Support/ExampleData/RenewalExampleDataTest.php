<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Support\ExampleData\RenewalExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RenewalExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_renewals_across_three_states(): void
    {
        $batchId = (string) Str::uuid();

        $graveRecords = $this->qualifyingGraveRecords();

        $renewals = RenewalExampleData::seed($batchId, $graveRecords);

        $statuses = array_map(static fn ($renewal): string => $renewal->status, $renewals);

        $this->assertContains('MENUNGGU_PEMBAYARAN', $statuses);
        $this->assertContains('DIBAYAR', $statuses);
        $this->assertContains('KEDALUWARSA', $statuses);

        foreach ($renewals as $renewal) {
            $this->assertSame($batchId, $renewal->fresh()->demo_batch_id);
        }
    }

    /**
     * Three distinct, currently-real grave records with a non-null
     * `due_date` and a fully-priced parent cemetery — the same set the
     * migration-seeded `CemeteryExampleData` fixtures already produce
     * under `RefreshDatabase` (confirmed directly against Postgres: 15
     * rows qualify), so no demo-specific grave record needs to be created
     * for this generator's own test.
     *
     * @return list<GraveRecord>
     */
    private function qualifyingGraveRecords(): array
    {
        return GraveRecord::query()
            ->whereNotNull('due_date')
            ->whereHas('cemetery', function ($query): void {
                $query->whereNotNull('price_min')
                    ->whereNotNull('price_source')
                    ->whereNotNull('price_effective_at');
            })
            ->orderBy('id')
            ->take(3)
            ->get()
            ->all();
    }
}
