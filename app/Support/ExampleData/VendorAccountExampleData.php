<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorUser;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Illuminate\Support\Facades\Hash;

/**
 * Two demo vendor accounts, each a real User (real login) linked to a real
 * Vendor via VendorUser. Direct model creation for Vendor/VendorUser/User
 * is correct here — confirmed during this subsystem's design that no
 * dedicated domain Action exists for any of the three anywhere in this
 * codebase (Filament's own CreateVendor page is a plain CreateRecord over
 * the Eloquent model).
 *
 * `vendor_users` is membership metadata only (see that model's own doc
 * block) — it is never read for authorization. `VendorPanelAccessPolicy`
 * requires the `ActorRole::VENDOR` role AND an active `vendor:` scope grant
 * in `scope_assignments` (`CurrentVendorScope::grantedVendorIds()`), so
 * both are granted here; the role alone would leave the account unable to
 * log into `/vendor`.
 */
final class VendorAccountExampleData
{
    private const string DEMO_PASSWORD = 'DemoContoh2026!';

    /**
     * @return array{vendors: list<Vendor>, users: list<User>}
     */
    public static function seed(string $batchId): array
    {
        $vendors = [];
        $users = [];

        foreach (range(0, 1) as $index) {
            $vendor = Vendor::query()->create([
                'name' => sprintf('Toko Contoh Demo %d', $index + 1),
                'is_active' => true,
            ]);
            TaggedAsDemoData::tag($vendor, $batchId);

            $user = User::query()->create([
                'name' => DemoContactData::personName($index),
                'email' => DemoContactData::email($index),
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]);
            TaggedAsDemoData::tag($user, $batchId);

            VendorUser::query()->create([
                'vendor_id' => $vendor->id,
                'actor_identifier' => (string) $user->id,
            ]);

            $roleAssignment = (new GrantActorRole)(
                actorIdentifier: (string) $user->id,
                role: ActorRole::VENDOR,
                reason: 'Demo seed data — live demo vendor account.',
                grantedBy: null,
            );
            TaggedAsDemoData::tag($roleAssignment, $batchId);

            $scopeAssignment = (new GrantScopeAssignment)(
                actorIdentifier: (string) $user->id,
                entityType: ScopeEntityType::VENDOR,
                entityId: $vendor->id,
                grantLevel: null,
                reason: 'Demo seed data — scoped to this demo vendor.',
                grantedBy: null,
            );
            TaggedAsDemoData::tag($scopeAssignment, $batchId);

            $vendors[] = $vendor;
            $users[] = $user;
        }

        return ['vendors' => $vendors, 'users' => $users];
    }
}
