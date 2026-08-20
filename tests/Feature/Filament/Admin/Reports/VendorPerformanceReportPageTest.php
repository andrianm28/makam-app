<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Reports;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Filament\Admin\Pages\VendorPerformanceReport;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `VendorPerformanceReport` — ADM-090/AC7's vendor-performance-by-period
 * report. `canAccess()` is role-only, the same
 * `MasterDataAdminAuthorizerContract` gate `VendorResource` uses — see
 * `VendorFulfillmentReport`'s doc block for why no business-entity scoping
 * applies.
 */
final class VendorPerformanceReportPageTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_and_bare_users_are_denied(): void
    {
        $this->assertFalse(VendorPerformanceReport::canAccess());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(VendorPerformanceReport::canAccess());
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(VendorPerformanceReport::canAccess(), "role {$role} should access");
        }
    }

    public function test_an_empty_period_renders_the_required_empty_state(): void
    {
        $user = $this->authorisedUser();

        $component = Livewire::actingAs($user)->test(VendorPerformanceReport::class);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $component->get('period'));
        $component->assertSee('Belum ada pesanan vendor pada periode ini')
            ->assertCount('reportRows', 0);
    }

    public function test_vendor_orders_are_aggregated_into_a_completion_rate(): void
    {
        $user = $this->authorisedUser();
        $vendor = $this->makeVendor();
        $listing = $this->makeListing($vendor);

        $this->makeVendorOrder($vendor, $listing, VendorProcessingStatus::SELESAI);
        $this->makeVendorOrder($vendor, $listing, VendorProcessingStatus::SELESAI);
        $this->makeVendorOrder($vendor, $listing, VendorProcessingStatus::DIBATALKAN);
        $this->makeVendorOrder($vendor, $listing, VendorProcessingStatus::KOMPLAIN);

        $component = Livewire::actingAs($user)->test(VendorPerformanceReport::class);

        $component->assertCount('reportRows', 1);

        $row = $component->get('reportRows')[0];
        $this->assertSame(4, $row['total']);
        $this->assertSame(2, $row['completed']);
        $this->assertSame(1, $row['cancelled']);
        $this->assertSame(1, $row['complaints']);
        $this->assertSame(0.5, $row['completion_rate']);
    }

    public function test_a_vendor_order_outside_the_period_is_excluded(): void
    {
        $user = $this->authorisedUser();
        $vendor = $this->makeVendor();
        $listing = $this->makeListing($vendor);

        $order = $this->makeVendorOrder($vendor, $listing, VendorProcessingStatus::SELESAI);

        VendorOrder::query()->where('id', $order->id)->update([
            'created_at' => CarbonImmutable::now()->subMonths(2),
        ]);

        $component = Livewire::actingAs($user)->test(VendorPerformanceReport::class);

        $component->assertCount('reportRows', 0);
    }

    public function test_a_malformed_period_renders_the_inline_validation_error(): void
    {
        $user = $this->authorisedUser();

        $component = Livewire::actingAs($user)->test(VendorPerformanceReport::class);

        $component->set('period', '2026-13')->call('loadReport');

        $component->assertSee('Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.')
            ->assertCount('reportRows', 0)
            ->assertHasErrors('period')
            ->assertSet('error', 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.');
    }

    private function authorisedUser(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        return $user;
    }

    private function makeVendor(): Vendor
    {
        return Vendor::query()->create(['name' => 'Vendor Uji', 'is_active' => true]);
    }

    private function makeListing(Vendor $vendor): VendorListing
    {
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

        return VendorListing::query()->create([
            'vendor_id' => (string) $vendor->id,
            'product_id' => $productId,
            'price_minor' => 150000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
    }

    private function makeVendorOrder(Vendor $vendor, VendorListing $listing, string $status): VendorOrder
    {
        return VendorOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'vendor_id' => (string) $vendor->id,
            'listing_id' => $listing->id,
            'customer_name' => 'Pelanggan Uji',
            'customer_phone' => '081234567890',
            'customer_email' => 'pelanggan@example.test',
            'status' => $status,
        ]);
    }
}
