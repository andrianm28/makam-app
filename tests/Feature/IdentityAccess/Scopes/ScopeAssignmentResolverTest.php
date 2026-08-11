<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Scopes;

use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `ScopeAssignmentResolver` is the read path over `scope_assignments`.
 * `currentActorIdentifier()` is exercised via `ActorContext` (see
 * `ScopeAssignmentGlobalScopeTest` for that path, driven through
 * `actingAs()` against the real, already-merged Batch 3.1 wiring); this
 * file exercises the two DB-reading methods directly against a
 * container-resolved instance, independent of which actor is "current".
 */
final class ScopeAssignmentResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_granted_entity_ids_returns_only_active_grants_for_the_given_actor_and_type(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10']);
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '20']);
        // Different actor — must not appear.
        ScopeAssignment::query()->create(['actor_identifier' => '2', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '30']);
        // Different entity type — must not appear.
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '40']);
        // Revoked — must not appear.
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '50'])->revoke();

        $resolver = $this->app->make(ScopeAssignmentResolver::class);

        $ids = $resolver->grantedEntityIds('1', ScopeEntityType::CEMETERY);

        sort($ids);
        $this->assertSame(['10', '20'], $ids);
    }

    public function test_granted_entity_ids_rejects_an_unknown_entity_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ScopeAssignmentResolver::class)->grantedEntityIds('1', 'spaceship');
    }

    public function test_scope_strings_for_actor_formats_as_entity_type_colon_entity_id(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10']);
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '20']);
        // Revoked — must not appear.
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::GRAVE, 'entity_id' => '30'])->revoke();

        $resolver = $this->app->make(ScopeAssignmentResolver::class);

        $scopes = $resolver->scopeStringsForActor('1');

        sort($scopes);
        $this->assertSame(['cemetery:10', 'vendor:20'], $scopes);
    }

    public function test_current_actor_identifier_reflects_the_authenticated_users_id(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $resolver = $this->app->make(ScopeAssignmentResolver::class);

        $this->assertSame($user->id, $resolver->currentActorIdentifier());
    }

    public function test_current_actor_identifier_is_null_for_a_guest(): void
    {
        $resolver = $this->app->make(ScopeAssignmentResolver::class);

        $this->assertNull($resolver->currentActorIdentifier());
    }

    /**
     * `actorsForEntity()` — added by the L2 notifications lane for
     * recipient resolution (ruling 3, `docs/superpowers/plans/
     * 2026-08-10-wave1a-notifications-decisions.md`). Reverse of
     * `grantedEntityIds()`: given an entity, which actors hold an active
     * grant on it.
     */
    public function test_actors_for_entity_returns_only_active_grants_on_the_given_entity(): void
    {
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10']);
        ScopeAssignment::query()->create(['actor_identifier' => '2', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10']);
        // Different entity id — must not appear.
        ScopeAssignment::query()->create(['actor_identifier' => '3', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '20']);
        // Different entity type, same id — must not appear.
        ScopeAssignment::query()->create(['actor_identifier' => '4', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '10']);
        // Revoked — must not appear.
        ScopeAssignment::query()->create(['actor_identifier' => '5', 'entity_type' => ScopeEntityType::CEMETERY, 'entity_id' => '10'])->revoke();

        $resolver = $this->app->make(ScopeAssignmentResolver::class);

        $actors = $resolver->actorsForEntity(ScopeEntityType::CEMETERY, '10');

        sort($actors);
        $this->assertSame(['1', '2'], $actors);
    }

    public function test_actors_for_entity_returns_distinct_actor_identifiers(): void
    {
        // Same actor granted the same entity twice (e.g. re-granted after a
        // prior revocation) — must appear only once.
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '99'])->revoke();
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '99']);

        $resolver = $this->app->make(ScopeAssignmentResolver::class);

        $actors = $resolver->actorsForEntity(ScopeEntityType::VENDOR, '99');

        $this->assertSame(['1'], $actors);
    }

    public function test_actors_for_entity_rejects_an_unknown_entity_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->app->make(ScopeAssignmentResolver::class)->actorsForEntity('spaceship', '10');
    }
}
