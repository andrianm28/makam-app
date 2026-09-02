<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\GraveRegistry;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GraveRegistryPublicQueryResolveOpenRecordAtTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();
    }

    public function test_it_resolves_the_real_record_at_the_given_index_of_the_open_subset(): void
    {
        $cemetery = $this->cemetery();
        $open = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Terbuka Satu',
            'access_mode' => GraveRecordAccessMode::OPEN,
        ]);

        $criteria = GraveSearchCriteria::make(cemeteryId: $cemetery->id, name: 'Contoh Terbuka Satu', block: '', deathDate: '');

        $resolved = GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 0);

        $this->assertNotNull($resolved);
        $this->assertSame($open->id, $resolved->id);
    }

    /**
     * A restricted (limited/closed) match must never be resolvable through
     * this method — it exists to let Screen 1 hand Screen 2 a grave the
     * visitor is actually allowed to renew, and Screen 2's own gate
     * (`RenewalPayment::terimaDanLanjutkan()`'s `access_mode` check)
     * already refuses a non-open record. Defence in depth: even if a caller
     * mis-indexes, this method itself only ever returns an OPEN record.
     */
    public function test_a_restricted_record_is_never_returned_even_if_it_matches(): void
    {
        $cemetery = $this->cemetery();
        GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Terbatas Dua',
            'access_mode' => GraveRecordAccessMode::CLOSED,
        ]);

        $criteria = GraveSearchCriteria::make(cemeteryId: $cemetery->id, name: 'Contoh Terbatas Dua', block: '', deathDate: '');

        $this->assertNull(GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 0));
    }

    public function test_an_out_of_range_index_returns_null_rather_than_throwing(): void
    {
        $cemetery = $this->cemetery();
        GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Tunggal',
            'access_mode' => GraveRecordAccessMode::OPEN,
        ]);

        $criteria = GraveSearchCriteria::make(cemeteryId: $cemetery->id, name: 'Contoh Tunggal', block: '', deathDate: '');

        $this->assertNull(GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 5));
    }

    public function test_it_mirrors_search_by_returning_nothing_for_a_criteria_with_no_terms(): void
    {
        $criteria = GraveSearchCriteria::make(cemeteryId: $this->cemetery()->id, name: '', block: '', deathDate: '');

        $this->assertNull(GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 0));
    }
}
