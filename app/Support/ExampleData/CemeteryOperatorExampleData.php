<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Illuminate\Support\Facades\Hash;

/**
 * One demo cemetery-operator account, real login, granted scope to exactly
 * one demo cemetery — enough for `/operator` and the plot floor map
 * (TPU/TPS dashboard roadmap, PRs #209-216) to have something real to show
 * in scoped mode, alongside an admin account seeing everything.
 */
final class CemeteryOperatorExampleData
{
    private const string DEMO_PASSWORD = 'DemoContoh2026!';

    private const int INDEX = 0;

    public static function seed(string $batchId, string $cemeteryId): User
    {
        $user = User::query()->create([
            'name' => DemoContactData::personName(self::INDEX + 100),
            'email' => DemoContactData::email(self::INDEX + 100),
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);
        TaggedAsDemoData::tag($user, $batchId);

        $roleAssignment = (new GrantActorRole)(
            actorIdentifier: (string) $user->id,
            role: ActorRole::CEMETERY_OPERATOR,
            reason: 'Demo seed data — live demo cemetery-operator account.',
            grantedBy: null,
        );
        TaggedAsDemoData::tag($roleAssignment, $batchId);

        $scopeAssignment = (new GrantScopeAssignment)(
            actorIdentifier: (string) $user->id,
            entityType: ScopeEntityType::CEMETERY,
            entityId: $cemeteryId,
            grantLevel: null,
            reason: 'Demo seed data — scoped to one demo cemetery.',
            grantedBy: null,
        );
        TaggedAsDemoData::tag($scopeAssignment, $batchId);

        return $user;
    }
}
