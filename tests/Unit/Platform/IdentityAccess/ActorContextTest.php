<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess;

use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pure unit coverage for the `ActorContext` value object — no database, no
 * container resolution. AC8's "single source consumers read" is only
 * meaningful if the shape itself is trustworthy in isolation first.
 */
final class ActorContextTest extends TestCase
{
    public function test_guest_has_no_identity_reference_and_is_not_authenticated(): void
    {
        $guest = ActorContext::guest();

        $this->assertNull($guest->identityReference);
        $this->assertFalse($guest->isAuthenticated());
    }

    public function test_guest_has_empty_roles_and_scopes(): void
    {
        $guest = ActorContext::guest();

        $this->assertSame([], $guest->roles);
        $this->assertSame([], $guest->scopes);
    }

    public function test_authenticated_actor_is_authenticated(): void
    {
        $actor = new ActorContext(identityReference: 42);

        $this->assertTrue($actor->isAuthenticated());
        $this->assertSame(42, $actor->identityReference);
    }

    public function test_identity_reference_accepts_a_string_for_a_future_non_integer_identity_source(): void
    {
        // Not exercised by the MVP adapter (local `users.id` is always
        // int), but the shape must not reject a string reference — a
        // future K1/K2-backed adapter may use one.
        $actor = new ActorContext(identityReference: 'k1-external-id-abc123');

        $this->assertSame('k1-external-id-abc123', $actor->identityReference);
        $this->assertTrue($actor->isAuthenticated());
    }

    public function test_has_role_never_reports_a_role_that_was_not_explicitly_supplied(): void
    {
        // Pure value-object behaviour: given whatever roles the caller
        // (only ever an IdentityAccessAdapter in practice) passed in,
        // hasRole() must not report anything beyond that list. This is no
        // longer a "roles are always empty" placeholder — roles resolve
        // for real via Adapters\LocalUsersTableIdentityAccessAdapter — but
        // the constructor's own contract is unchanged and still worth
        // pinning in isolation from any database.
        $actor = new ActorContext(identityReference: 1, roles: ['admin']);

        $this->assertTrue($actor->hasRole('admin'));
        $this->assertFalse($actor->hasRole('finance'));
    }

    public function test_has_scope_never_reports_a_scope_that_was_not_explicitly_supplied(): void
    {
        // Same purpose as the roles test above, for scopes — no longer a
        // placeholder assertion (scopes resolve for real via
        // Scopes\ScopeAssignmentReader), but the constructor's own
        // contract is unchanged.
        $actor = new ActorContext(identityReference: 1, scopes: ['cemetery:1']);

        $this->assertTrue($actor->hasScope('cemetery:1'));
        $this->assertFalse($actor->hasScope('cemetery:2'));
    }

    public function test_last_authenticated_at_is_carried_through_unmodified(): void
    {
        $timestamp = CarbonImmutable::parse('2026-07-25T10:00:00Z');

        $actor = new ActorContext(identityReference: 1, lastAuthenticatedAt: $timestamp);

        $this->assertTrue($timestamp->equalTo($actor->lastAuthenticatedAt));
    }

    /**
     * The live mutation attempt IS the test. Asking reflection whether the
     * declaration says `readonly` proves the declaration; attempting the write
     * proves the guarantee AC8's "single source consumers read" actually rests
     * on — that a consumer holding an `ActorContext` cannot have its roles or
     * scopes swapped out from under it after resolution.
     *
     * The message is asserted, not merely the `Error`: removing the property,
     * or reducing it to private, throws here too, and neither is immutability.
     */
    #[DataProvider('immutablePropertyProvider')]
    public function test_actor_context_is_immutable_by_construction(string $property, mixed $replacement): void
    {
        $actor = new ActorContext(
            identityReference: 1,
            roles: ['admin'],
            scopes: ['cemetery:1'],
            lastAuthenticatedAt: CarbonImmutable::parse('2026-07-25T10:00:00Z'),
        );
        $original = $actor->{$property};

        try {
            $actor->{$property} = $replacement;
            $this->fail("ActorContext::\${$property} accepted a write — it must be readonly.");
        } catch (Error $e) {
            $this->assertStringContainsString(
                'Cannot modify readonly property',
                $e->getMessage(),
                "ActorContext::\${$property} refused the write for the wrong reason: {$e->getMessage()}"
            );
        }

        $this->assertSame($original, $actor->{$property});
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function immutablePropertyProvider(): array
    {
        return [
            'identityReference' => ['identityReference', 999],
            'roles' => ['roles', ['superuser']],
            'scopes' => ['scopes', ['cemetery:*']],
            'lastAuthenticatedAt' => ['lastAuthenticatedAt', null],
        ];
    }
}
