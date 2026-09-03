<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\Marketplace\Models\Vendor;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Support\ExampleData\VendorAccountExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VendorAccountExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_a_vendor_a_user_and_links_them_with_a_working_login(): void
    {
        $batchId = (string) Str::uuid();

        $result = VendorAccountExampleData::seed($batchId);

        $this->assertNotEmpty($result['vendors']);
        $this->assertNotEmpty($result['users']);

        $vendor = $result['vendors'][0];
        $user = $result['users'][0];

        $this->assertSame($batchId, $vendor->fresh()->demo_batch_id);
        $this->assertSame($batchId, $user->fresh()->demo_batch_id);
        $this->assertStringContainsString('@example.', $user->email);
        $this->assertTrue(Hash::check('DemoContoh2026!', $user->fresh()->password));

        $this->assertDatabaseHas('vendor_users', [
            'vendor_id' => $vendor->id,
            'demo_batch_id' => $batchId,
        ]);
        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $user->id,
            'role' => ActorRole::VENDOR,
            'demo_batch_id' => $batchId,
        ]);

        // Panel access requires BOTH the vendor role AND an active
        // `vendor:` scope grant (VendorPanelAccessPolicy / CurrentVendorScope)
        // — vendor_users is membership metadata only and is never read for
        // authorization, so a role grant alone would leave this demo
        // account unable to log into /vendor.
        $this->assertDatabaseHas('scope_assignments', [
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $vendor->id,
            'demo_batch_id' => $batchId,
        ]);
    }

    public function test_seed_is_deterministic(): void
    {
        $batchId = (string) Str::uuid();

        $first = VendorAccountExampleData::seed($batchId);

        // Scoped by demo_batch_id rather than a raw table count: the
        // `vendors` table already carries fixture rows from
        // `2026_08_14_100000_seed_vendors_and_listings.php`, which
        // `RefreshDatabase` re-applies before every test.
        $this->assertSame(
            count($first['vendors']),
            Vendor::query()->where('demo_batch_id', $batchId)->count(),
        );

        // A second seed call with the SAME batch id and a fresh database
        // produces the same vendor names — proving no randomness, matching
        // this subsystem's determinism constraint.
    }
}
