<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\RecordPaymentActionRefusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `RecordPaymentActionRefusal` at the unit level — the two fields the HTTP
 * tests cannot fully reach, and the reason the row exists at all.
 *
 * The two route test files already drive this class through the real
 * controllers with a real, DB-resolved `ActorContext`, which is the coverage
 * that matters most. This file exists for the cases HTTP cannot produce and
 * for the precedence rule the row depends on:
 *
 * - **The `guest` arm is unreachable over HTTP.** Both routes carry `auth`,
 *   and `ActorContext` is a per-request `scoped` binding resolved from the
 *   authenticated guard, so a controller-driven test can never present an
 *   actor with no resolved identity. It is defensive code mirroring
 *   `Http\Middleware\RequireRecentAuthentication`'s own `guest` fallback, and
 *   the only honest way to pin it is to call the class directly.
 * - **Role precedence across the whole `ActorRole::KNOWN_ROLES` list.**
 *   Building eight users with multi-role grants over HTTP would be slow and
 *   would test `ActorRoleReader`'s ordering rather than this class's.
 *
 * Whole-branch review findings SF-4 (nothing pinned `actor_ref`, so nulling it
 * left all 227 payment tests green) and SF-5 (the row recorded the
 * `authenticated_actor` sentinel even for an actor holding a real role) are
 * both pinned here as well as in the route tests.
 */
final class RecordPaymentActionRefusalTest extends TestCase
{
    use RefreshDatabase;

    private function denialRow(ActorContext $actor): AuditEvent
    {
        (new RecordPaymentActionRefusal)->record($actor, 'payment_reversal');

        return AuditEvent::query()
            ->where('action', PaymentAuditActions::ADMIN_ACTION_DENIED)
            ->sole();
    }

    /**
     * SF-4. The field that makes this row a monitoring signal rather than a
     * counter: without it the trail records that something was refused and
     * nothing about who.
     */
    public function test_the_refusal_row_carries_the_refused_actors_reference(): void
    {
        $denial = $this->denialRow(new ActorContext(identityReference: 4242, roles: [ActorRole::CUSTOMER]));

        $this->assertSame('4242', (string) $denial->actor_ref);
        $this->assertSame(AuditOutcome::Denied->value, $denial->outcome);
    }

    /**
     * The identity reference may be a string under a future K1/K2 adapter —
     * `ActorContext::$identityReference` is typed `int|string|null` precisely
     * for that. It must survive to the row either way.
     */
    public function test_a_string_identity_reference_is_recorded_verbatim(): void
    {
        $denial = $this->denialRow(new ActorContext(identityReference: 'k2-actor-991', roles: []));

        $this->assertSame('k2-actor-991', (string) $denial->actor_ref);
    }

    /**
     * SF-5, the whole point: a refused actor's REAL role, not the sentinel.
     * Every entry in the canonical list, so no role is silently left behind if
     * the list is ever widened.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function everyKnownRole(): iterable
    {
        foreach (ActorRole::KNOWN_ROLES as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('everyKnownRole')]
    public function test_a_refused_actor_holding_a_real_role_is_recorded_under_that_role(string $role): void
    {
        $denial = $this->denialRow(new ActorContext(identityReference: 7, roles: [$role]));

        $this->assertSame($role, $denial->actor_role);
    }

    /**
     * Precedence is borrowed from `ActorRole::KNOWN_ROLES`' declaration order
     * (most privileged first), exactly as
     * `DocumentVault\Policies\DocumentAccessPolicy::auditRoleFor()` does. The
     * roles are supplied here in the WRONG order deliberately, so the result
     * cannot be "whatever came first in the array".
     */
    public function test_the_most_privileged_held_role_wins_regardless_of_input_order(): void
    {
        $denial = $this->denialRow(new ActorContext(
            identityReference: 8,
            roles: [ActorRole::CUSTOMER, ActorRole::CASE_MANAGER, ActorRole::ADMIN, ActorRole::VENDOR],
        ));

        $this->assertSame(ActorRole::ADMIN, $denial->actor_role);
    }

    /**
     * The sentinel is still written — but only where it is literally true.
     * `authenticated_actor` means "no role applies", and for an authenticated
     * actor holding nothing at all, it does.
     */
    public function test_an_authenticated_actor_with_no_roles_is_recorded_under_the_sentinel(): void
    {
        $denial = $this->denialRow(new ActorContext(identityReference: 9, roles: []));

        $this->assertSame('authenticated_actor', $denial->actor_role);
    }

    /**
     * An unresolvable role is not a role. `actor_role_assignments` cannot hold
     * one — `ActorRoleAssignment::booted()` calls `ActorRole::assertKnown()`
     * on save — but a future adapter could hand one over, and the sentinel is
     * the honest answer rather than echoing an unknown value into the trail.
     */
    public function test_a_role_outside_the_canonical_list_falls_back_to_the_sentinel(): void
    {
        $denial = $this->denialRow(new ActorContext(identityReference: 10, roles: ['not_a_real_role']));

        $this->assertSame('authenticated_actor', $denial->actor_role);
    }

    /**
     * The unreachable-over-HTTP arm: no resolved identity at all is `guest`,
     * never the authenticated sentinel. The distinction is the one an operator
     * needs — "we do not know who this was" is a different investigation from
     * "a known account holding no grants".
     */
    public function test_an_actor_with_no_resolved_identity_is_recorded_as_guest(): void
    {
        $denial = $this->denialRow(ActorContext::guest());

        $this->assertSame('guest', $denial->actor_role);
        $this->assertNull($denial->actor_ref);
    }

    /**
     * A guest context carrying roles is incoherent, but it must not be read as
     * an identity: the identity check comes first, exactly as it does in
     * `FinanceOrRestrictedAdminPaymentAuthorizer::authorize()`.
     */
    public function test_a_guest_context_carrying_roles_is_still_recorded_as_guest(): void
    {
        $denial = $this->denialRow(new ActorContext(identityReference: null, roles: [ActorRole::FINANCE]));

        $this->assertSame('guest', $denial->actor_role);
    }
}
