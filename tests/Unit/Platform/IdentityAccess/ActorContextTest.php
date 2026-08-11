<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess;

use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
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

    public function test_guest_mfa_state_is_not_applicable(): void
    {
        $this->assertSame(ActorContext::MFA_STATE_NOT_APPLICABLE, ActorContext::guest()->mfaState);
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

    public function test_default_mfa_state_for_an_explicitly_constructed_actor_is_not_applicable_unless_overridden(): void
    {
        // Documents the constructor default explicitly — callers that
        // build an ActorContext without stating mfaState (only the
        // adapter should do this in practice) get the safest possible
        // default, never an implicit "satisfied" state.
        $actor = new ActorContext(identityReference: 1);

        $this->assertSame(ActorContext::MFA_STATE_NOT_APPLICABLE, $actor->mfaState);
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

    public function test_actor_context_is_immutable_by_construction(): void
    {
        // readonly properties: this is a compile-time guarantee, but assert
        // the property is declared readonly via reflection so a future
        // refactor that accidentally drops `readonly` fails loudly here
        // instead of only being caught by a live mutation attempt.
        $reflection = new \ReflectionClass(ActorContext::class);

        foreach (['identityReference', 'roles', 'scopes', 'mfaState', 'lastAuthenticatedAt'] as $property) {
            $this->assertTrue(
                $reflection->getProperty($property)->isReadOnly(),
                "ActorContext::\${$property} must be readonly."
            );
        }
    }
}
