<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorUser;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VendorIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_vendor_id_is_a_string_uuid_usable_as_a_scope_entity_id(): void
    {
        $vendor = Vendor::create(['name' => 'Toko Bunga Melati', 'is_active' => true]);

        $this->assertIsString($vendor->id);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $vendor->id
        );
        $this->assertSame($vendor->id, (string) $vendor->id);
    }

    public function test_vendor_users_records_membership_without_granting_any_access(): void
    {
        $vendor = Vendor::create(['name' => 'Batu Nisan Jaya', 'is_active' => true]);

        $membership = VendorUser::create([
            'vendor_id' => $vendor->id,
            'actor_identifier' => 'actor-77',
        ]);

        $this->assertSame($vendor->id, $membership->vendor_id);

        // The membership row exists, but NO scope assignment was written.
        // vendor_users must never be an authorization source.
        $this->assertDatabaseMissing('scope_assignments', [
            'actor_identifier' => 'actor-77',
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $vendor->id,
        ]);
    }

    public function test_inactive_vendors_are_excluded_by_the_active_scope(): void
    {
        Vendor::create(['name' => 'Aktif', 'is_active' => true]);
        Vendor::create(['name' => 'Nonaktif', 'is_active' => false]);

        // The bootstrap seed migration ships five ACTIVE example vendors,
        // so the active set is no longer exactly the test's own rows —
        // assert membership, not an exhaustive list.
        $active = Vendor::active()->pluck('name')->all();
        $this->assertContains('Aktif', $active);
        $this->assertNotContains('Nonaktif', $active);
    }
}
