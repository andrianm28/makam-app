<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `ScopeAssignmentReader` is the stateless extraction of the three actor-
 * keyed `scope_assignments` queries that used to live on
 * `ScopeAssignmentResolver`. See that class's doc block for why: injecting
 * `ScopeAssignmentResolver` (which takes `ActorContext`) into the identity
 * adapter would close a container cycle. This reader has no constructor
 * dependencies at all, so the adapter can depend on it safely.
 *
 * These tests deliberately duplicate coverage already present in
 * `ScopeAssignmentResolverTest` for the moved methods — that resolver test
 * now exercises the delegation path, this one exercises the reader
 * directly, and both must keep passing.
 */
final class ScopeAssignmentReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_constructible_without_an_actor_context(): void
    {
        // The whole point of this class: no ActorContext in its graph, so
        // the identity adapter can depend on it without closing a
        // container cycle.
        $this->assertInstanceOf(ScopeAssignmentReader::class, new ScopeAssignmentReader);
    }

    public function test_scope_strings_use_the_entity_type_colon_entity_id_shape(): void
    {
        ScopeAssignment::create([
            'actor_identifier' => '7',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '1',
        ]);

        $this->assertSame(['cemetery:1'], (new ScopeAssignmentReader)->scopeStringsForActor(7));
    }

    public function test_revoked_grants_are_excluded(): void
    {
        $grant = ScopeAssignment::create([
            'actor_identifier' => '7',
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => '3',
        ]);
        $grant->revoke();

        $this->assertSame([], (new ScopeAssignmentReader)->scopeStringsForActor(7));
        $this->assertSame([], (new ScopeAssignmentReader)->grantedEntityIds(7, ScopeEntityType::VENDOR));
    }

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

        $ids = (new ScopeAssignmentReader)->grantedEntityIds('1', ScopeEntityType::CEMETERY);

        sort($ids);
        $this->assertSame(['10', '20'], $ids);
    }

    public function test_granted_entity_ids_rejects_an_unknown_entity_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ScopeAssignmentReader)->grantedEntityIds('1', 'spaceship');
    }

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

        $actors = (new ScopeAssignmentReader)->actorsForEntity(ScopeEntityType::CEMETERY, '10');

        sort($actors);
        $this->assertSame(['1', '2'], $actors);
    }

    public function test_actors_for_entity_returns_distinct_actor_identifiers(): void
    {
        // Same actor granted the same entity twice (e.g. re-granted after a
        // prior revocation) — must appear only once.
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '99'])->revoke();
        ScopeAssignment::query()->create(['actor_identifier' => '1', 'entity_type' => ScopeEntityType::VENDOR, 'entity_id' => '99']);

        $actors = (new ScopeAssignmentReader)->actorsForEntity(ScopeEntityType::VENDOR, '99');

        $this->assertSame(['1'], $actors);
    }

    public function test_actors_for_entity_rejects_an_unknown_entity_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ScopeAssignmentReader)->actorsForEntity('spaceship', '10');
    }
}
