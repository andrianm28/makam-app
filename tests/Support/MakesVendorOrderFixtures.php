<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixture for the vendor-order tests: a vendor, one product + listing,
 * one order on that listing, and an actor granted exactly that vendor via
 * `scope_assignments`.
 *
 * The grant is load-bearing, not ceremony: `ScopesToCurrentVendor` closes the
 * `/vendor/pesanan` resource query to the granted vendor, so without it the
 * edit page cannot mount the record at all. `forgetScopedInstances()` discards
 * any actor-context/scope-resolver container instance resolved before the
 * grant (they are `scoped()` bindings — see `VendorPanelScopingTest`'s
 * `refreshActorContext()` for the same reasoning).
 */
trait MakesVendorOrderFixtures
{
    /**
     * @return array{User, VendorOrder}
     */
    private function vendorOrderForGrantedVendor(
        string $status = VendorProcessingStatus::MENUNGGU_VENDOR,
    ): array {
        $vendor = Vendor::query()->create(['name' => 'Vendor Uji', 'is_active' => true]);

        $productId = DB::table('products')->insertGetId([
            'code' => 'PRD-'.Str::random(8),
            'category' => 'KARANGAN_BUNGA',
            'name' => 'Produk Uji',
            'description' => 'Deskripsi uji.',
            'base_price_idr' => 100000,
            'price_version' => 1,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $listing = VendorListing::query()->create([
            'vendor_id' => (string) $vendor->id,
            'product_id' => $productId,
            'price_minor' => 150000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $order = VendorOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'vendor_id' => (string) $vendor->id,
            'listing_id' => $listing->id,
            'customer_name' => 'Pelanggan Uji',
            'customer_phone' => '081234567890',
            'customer_email' => 'pelanggan@example.test',
            'status' => $status,
        ]);

        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => (string) $vendor->id,
        ]);

        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        return [$user, $order];
    }
}
