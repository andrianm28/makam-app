<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryCapability;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * requirements.md AC6: "THE SYSTEM SHALL present Makam Tumpang
 * availability explicitly at the location/package/class level." Proves the
 * seeded `cemetery_packages` fixtures actually express both granularities
 * (package-level: `class_label = null`; class-level: `class_label` set)
 * for a single named offering, and that the availability-status closed
 * list is enforced.
 */
final class CemeteryPackageAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_makam_tumpang_is_expressed_at_both_package_and_class_level(): void
    {
        $cemetery = Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->firstOrFail();

        $tumpangRows = CemeteryPackage::query()
            ->where('cemetery_id', $cemetery->id)
            ->where('name', 'Makam Tumpang')
            ->get();

        // One package-level row (class_label null) ...
        $this->assertCount(1, $tumpangRows->filter(fn (CemeteryPackage $p) => $p->isPackageLevel()));

        // ... and at least one class-level breakdown (class_label set).
        $this->assertGreaterThanOrEqual(
            1,
            $tumpangRows->filter(fn (CemeteryPackage $p) => ! $p->isPackageLevel())->count()
        );

        $classLabels = $tumpangRows->pluck('class_label')->filter()->all();
        $this->assertContains('Kelas A', $classLabels);
    }

    public function test_cemetery_packages_relation_returns_only_this_cemeterys_rows(): void
    {
        $jakarta = Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->firstOrFail();
        $depok = Cemetery::query()->where('slug', 'tpu-depok-sawangan')->firstOrFail();

        $this->assertTrue($jakarta->packages->count() > 0);
        $this->assertTrue($depok->packages->count() > 0);

        $jakartaIds = $jakarta->packages->pluck('id');
        $depokIds = $depok->packages->pluck('id');

        $this->assertEmpty($jakartaIds->intersect($depokIds));
    }

    public function test_every_seeded_package_has_a_known_availability_status(): void
    {
        $packages = CemeteryPackage::query()->get();

        $this->assertGreaterThan(0, $packages->count());

        foreach ($packages as $package) {
            $this->assertTrue(CemeteryPackageAvailabilityStatus::isKnown($package->availability_status));
        }
    }

    public function test_the_model_rejects_an_unknown_availability_status(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();

        $this->expectException(InvalidArgumentException::class);

        CemeteryPackage::create([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Uji Coba',
            'class_label' => null,
            'availability_status' => 'NOT_A_REAL_STATUS',
        ]);
    }

    public function test_most_seeded_cemeteries_have_no_package_data_and_that_is_honest_not_a_bug(): void
    {
        // AC6 only requires package/class availability to be EXPRESSIBLE,
        // not populated for every cemetery — see the seed migration's own
        // doc block. Only two of the ten seeded cemeteries carry example
        // package rows.
        $cemeteriesWithPackages = Cemetery::query()
            ->whereHas('packages')
            ->count();

        $this->assertSame(2, $cemeteriesWithPackages);
    }
}
