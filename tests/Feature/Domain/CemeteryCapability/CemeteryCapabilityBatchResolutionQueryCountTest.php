<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryCapability;

use App\Domain\CemeteryCapability\Actions\ResolveCemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Directory\Support\PublicCapabilityProjection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-05 (Phase 3 Batch M7a). `CemeteryDirectoryIndex::render()` and
 * `BookingWizard::render()` used to resolve each cemetery card's capability
 * profile and active packages with ONE query PER CEMETERY — `$cemeteries
 * ->map(fn ($cemetery) => ResolveCemeteryCapabilityProfile ...)` and the
 * equivalent for `CemeteryPublicQuery::activePackages()`. This proves the
 * batch resolvers introduced for that fix run in ONE query regardless of
 * how many cemeteries are resolved, instead of query count scaling with N.
 */
final class CemeteryCapabilityBatchResolutionQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private function publishedCemetery(): Cemetery
    {
        return Cemetery::factory()->create([
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
        ]);
    }

    public function test_resolving_capability_profiles_for_many_cemeteries_runs_one_query(): void
    {
        $cemeteries = new Collection(collect(range(1, 5))->map(fn () => $this->publishedCemetery())->all());

        // Give two of the five a real activated profile row, so the batch
        // resolver's "current row exists" AND "falls back to safe defaults"
        // branches are both exercised in the same call — not just the
        // all-fallback case, which could trivially pass with zero queries.
        CemeteryCapabilityProfile::query()->create(array_merge(
            CemeteryCapabilityProfile::safeDefaults(),
            [
                'cemetery_id' => $cemeteries[0]->id,
                'version_number' => 1,
                'source' => 'test-fixture',
                'owner' => 'qa@makam.co.id',
                'evidence' => 'test',
                'rollback_plan' => 'test',
                'effective_at' => now(),
                'superseded_at' => null,
            ],
        ));

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $profiles = (new ResolveCemeteryCapabilityProfile)->forMany($cemeteries);

        $capabilityQueries = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'cemetery_capability_profiles'),
        );

        $this->assertCount(5, $profiles);
        $this->assertCount(
            1,
            $capabilityQueries,
            'Expected exactly ONE query against cemetery_capability_profiles for 5 cemeteries, got: '.implode(' | ', $capabilityQueries),
        );

        // Also assert the public-projection wrapper (the actual call site
        // used by CemeteryDirectoryIndex/BookingWizard) preserves the same
        // one-query behaviour.
        $queries = [];
        PublicCapabilityProjection::forMany($cemeteries);
        $capabilityQueries = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'cemetery_capability_profiles'),
        );
        $this->assertCount(1, $capabilityQueries);
    }

    public function test_resolving_active_packages_for_many_cemeteries_runs_one_query(): void
    {
        $cemeteries = new Collection(collect(range(1, 5))->map(fn () => $this->publishedCemetery())->all());

        foreach ($cemeteries as $index => $cemetery) {
            CemeteryPackage::query()->create([
                'cemetery_id' => $cemetery->id,
                'name' => 'Paket '.$index,
                'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
                'is_active' => true,
                'sort_order' => 0,
            ]);
        }

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = CemeteryPublicQuery::activePackagesForMany($cemeteries);

        $packageQueries = array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'cemetery_packages'),
        );

        $this->assertCount(5, $result);
        foreach ($cemeteries as $cemetery) {
            $this->assertCount(1, $result[$cemetery->id]);
        }
        $this->assertCount(
            1,
            $packageQueries,
            'Expected exactly ONE query against cemetery_packages for 5 cemeteries, got: '.implode(' | ', $packageQueries),
        );
    }
}
