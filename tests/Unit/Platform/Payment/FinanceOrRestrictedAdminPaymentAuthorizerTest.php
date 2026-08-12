<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;
use App\Platform\Payment\FinanceOrRestrictedAdminPaymentAuthorizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The payment admin actions' authorization policy in isolation. The route
 * tests in `tests/Feature/Payment/` cover the same policy through the whole
 * live chain (`actor_role_assignments` → `ActorRoleReader` → adapter →
 * resolver → controller); this file pins the decision itself, including the
 * cases that are awkward to reach over HTTP.
 *
 * A plain PHPUnit `TestCase`, not `Tests\TestCase`: the authorizer touches
 * no database, no container, and no request. Booting the framework for it
 * would hide a dependency creeping in later.
 */
final class FinanceOrRestrictedAdminPaymentAuthorizerTest extends TestCase
{
    private function authorizer(): FinanceOrRestrictedAdminPaymentAuthorizer
    {
        return new FinanceOrRestrictedAdminPaymentAuthorizer;
    }

    public function test_it_implements_the_module_contract(): void
    {
        $this->assertInstanceOf(PaymentActionAuthorizer::class, $this->authorizer());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function authorizedRoles(): iterable
    {
        yield 'finance' => [ActorRole::FINANCE];
        yield 'restricted_admin' => [ActorRole::RESTRICTED_ADMIN];
    }

    #[DataProvider('authorizedRoles')]
    public function test_an_authorized_role_is_returned_by_name(string $role): void
    {
        $approved = $this->authorizer()->authorize(
            new ActorContext(identityReference: 77, roles: [$role]),
        );

        $this->assertSame($role, $approved);
    }

    /**
     * A guest context. The identity check runs first and on its own, so this
     * refuses even if a roles array were somehow populated — an actor with
     * no resolved identity is never authorized, whatever else it carries.
     */
    public function test_a_null_identity_reference_is_refused(): void
    {
        $this->expectException(PaymentActionNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: null, roles: [ActorRole::FINANCE]),
        );
    }

    public function test_a_guest_context_is_refused(): void
    {
        $this->expectException(PaymentActionNotAuthorisedException::class);

        $this->authorizer()->authorize(ActorContext::guest());
    }

    /**
     * An empty role list means "this actor holds no grants today", never
     * "no role required." This is the assertion that fails first if anyone
     * ever inverts that reading.
     */
    public function test_an_empty_role_list_is_refused(): void
    {
        $this->expectException(PaymentActionNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: 77, roles: []),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unauthorizedRoles(): iterable
    {
        // Every KNOWN_ROLES entry that is NOT on the allow-list, including
        // `admin` — a plain admin is deliberately not a payout/refund role
        // per `docs/security/rbac-matrix.md`, which grants that row to a
        // RESTRICTED admin and to finance only.
        yield 'admin' => [ActorRole::ADMIN];
        yield 'operator' => [ActorRole::OPERATOR];
        yield 'case_manager' => [ActorRole::CASE_MANAGER];
        yield 'vendor' => [ActorRole::VENDOR];
        yield 'customer' => [ActorRole::CUSTOMER];
        yield 'system' => [ActorRole::SYSTEM];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_a_real_but_unauthorized_role_is_refused(string $role): void
    {
        $this->expectException(PaymentActionNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: 77, roles: [$role]),
        );
    }

    /**
     * The `authenticated_actor` audit sentinel is not a grantable role —
     * `ActorRole`'s own doc block forbids it ever becoming one, and
     * `ActorRoleAssignment` rejects it on save. Pinned here because it is
     * exactly the string both controllers used to pass as their audit role,
     * so an accidental round trip through this policy must refuse.
     */
    public function test_the_authenticated_actor_sentinel_is_not_a_role(): void
    {
        $this->expectException(PaymentActionNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: 77, roles: ['authenticated_actor']),
        );
    }

    /**
     * `ActorRole::KNOWN_ROLES` declaration order is precedence order, most
     * privileged first. An actor holding both authorized roles must be
     * recorded under the more privileged one regardless of the order the
     * roles arrive in, since `ActorRoleReader` sorts but a future caller
     * might not.
     */
    public function test_the_most_privileged_role_wins_regardless_of_input_order(): void
    {
        $this->assertSame(
            ActorRole::RESTRICTED_ADMIN,
            $this->authorizer()->authorize(new ActorContext(
                identityReference: 77,
                roles: [ActorRole::FINANCE, ActorRole::RESTRICTED_ADMIN],
            )),
        );

        $this->assertSame(
            ActorRole::RESTRICTED_ADMIN,
            $this->authorizer()->authorize(new ActorContext(
                identityReference: 77,
                roles: [ActorRole::RESTRICTED_ADMIN, ActorRole::FINANCE],
            )),
        );
    }

    /**
     * A string identity reference is as valid as an integer one —
     * `ActorContext::$identityReference` is typed `int|string|null` so a
     * future K1/K2 adapter can key on an external id. The policy must not
     * quietly depend on the local autoincrement shape.
     */
    public function test_a_string_identity_reference_is_accepted(): void
    {
        $this->assertSame(
            ActorRole::FINANCE,
            $this->authorizer()->authorize(new ActorContext(
                identityReference: 'external-identity-77',
                roles: [ActorRole::FINANCE],
            )),
        );
    }

    /**
     * The refusal must name nothing beyond the actor reference — no role
     * list, no record id, no amount. See the exception's own doc block.
     */
    public function test_the_refusal_message_leaks_nothing_beyond_the_actor_reference(): void
    {
        try {
            $this->authorizer()->authorize(
                new ActorContext(identityReference: 77, roles: [ActorRole::CUSTOMER]),
            );

            $this->fail('An unauthorized role must be refused.');
        } catch (PaymentActionNotAuthorisedException $exception) {
            $this->assertStringContainsString('77', $exception->getMessage());
            $this->assertStringNotContainsString(ActorRole::CUSTOMER, $exception->getMessage());
        }
    }
}
