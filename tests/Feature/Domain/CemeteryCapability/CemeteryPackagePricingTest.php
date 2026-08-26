<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryCapability;

use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The real gap a 25 Aug 2026 scoping investigation found: `CemeteryPackage`
 * carried no price columns at all, so the public site could only ever show
 * ONE aggregate price range per cemetery, never a price per package/class.
 * `2026_08_26_110000_add_price_fields_to_cemetery_packages_table.php` adds
 * `price_min`/`price_max`/`price_currency`/`price_source`/
 * `price_effective_at`, mirroring `Cemetery`'s own five price columns
 * exactly (see that migration's doc block for why, not the `Money`/
 * `price_versions` convention).
 *
 * This suite proves the MODEL-level contract: `price_effective_at` is
 * stamped automatically from a priced-field change, never hand-entered,
 * and clearing a price clears its effective date honestly rather than
 * leaving a stale "as of" date attached to no price.
 */
final class CemeteryPackagePricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unpriced_package_has_no_price_fields_set(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();

        $package = CemeteryPackage::create([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Uji Coba',
            'class_label' => null,
            'availability_status' => 'AVAILABLE',
        ]);

        $this->assertNull($package->price_min);
        $this->assertNull($package->price_max);
        $this->assertNull($package->price_source);
        $this->assertNull($package->price_effective_at);
        // `price_currency` was never passed to `create()`, so the in-memory
        // model has no value for it until refreshed — the column's own
        // `default('IDR')` is a DB-level default, not one Eloquent
        // back-fills onto an unrefreshed model (`Cemetery` has the same
        // property).
        $this->assertSame('IDR', $package->fresh()->price_currency);
    }

    public function test_setting_a_price_on_create_stamps_effective_at_automatically(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();

        $before = now();

        $package = CemeteryPackage::create([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Uji Coba',
            'class_label' => null,
            'availability_status' => 'AVAILABLE',
            'price_min' => '3000000.00',
            'price_max' => '5000000.00',
            'price_source' => 'Daftar harga pengelola',
        ]);

        $this->assertNotNull($package->price_effective_at);
        $this->assertGreaterThanOrEqual($before->timestamp, $package->price_effective_at->timestamp);
    }

    public function test_changing_the_price_on_an_existing_package_re_stamps_effective_at(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();

        $package = CemeteryPackage::create([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Uji Coba',
            'class_label' => null,
            'availability_status' => 'AVAILABLE',
            'price_min' => '3000000.00',
            'price_max' => '5000000.00',
        ]);

        $firstStamp = $package->price_effective_at;
        $this->assertNotNull($firstStamp);

        $this->travel(2)->days();

        $package->update(['price_max' => '6000000.00']);
        $package->refresh();

        $this->assertNotNull($package->price_effective_at);
        $this->assertGreaterThan($firstStamp->timestamp, $package->price_effective_at->timestamp);
    }

    public function test_editing_an_unrelated_field_does_not_re_stamp_effective_at(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();

        $package = CemeteryPackage::create([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Uji Coba',
            'class_label' => null,
            'availability_status' => 'AVAILABLE',
            'price_min' => '3000000.00',
            'price_max' => '5000000.00',
        ]);

        $firstStamp = $package->price_effective_at;

        $this->travel(2)->days();

        $package->update(['description' => 'Deskripsi baru, harga tidak berubah.']);
        $package->refresh();

        $this->assertTrue($firstStamp->equalTo($package->price_effective_at));
    }

    public function test_clearing_a_price_clears_its_effective_date_honestly(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();

        $package = CemeteryPackage::create([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Uji Coba',
            'class_label' => null,
            'availability_status' => 'AVAILABLE',
            'price_min' => '3000000.00',
            'price_max' => '5000000.00',
        ]);

        $this->assertNotNull($package->price_effective_at);

        $package->update(['price_min' => null, 'price_max' => null]);
        $package->refresh();

        $this->assertNull($package->price_effective_at);
    }

    /**
     * A caller that explicitly sets `price_effective_at` itself (e.g. a
     * fixture backfilling a historical date) is not silently overridden by
     * the auto-stamp hook — the guard in `CemeteryPackage::booted()` checks
     * `isDirty('price_effective_at')` for exactly this reason.
     */
    public function test_an_explicitly_set_effective_at_is_not_overwritten(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();
        // Whole-second precision: the `timestamp` column and the date
        // cast's set/get round-trip do not preserve microseconds, so a
        // sub-second `$historical` would make this assertion flaky for a
        // reason unrelated to what this test actually checks.
        $historical = now()->subYear()->startOfSecond();

        $package = new CemeteryPackage([
            'cemetery_id' => $cemetery->id,
            'name' => 'Makam Uji Coba',
            'class_label' => null,
            'availability_status' => 'AVAILABLE',
            'price_min' => '3000000.00',
            'price_max' => '5000000.00',
        ]);
        $package->price_effective_at = $historical;
        $package->save();

        $this->assertTrue($historical->equalTo($package->price_effective_at));
    }
}
