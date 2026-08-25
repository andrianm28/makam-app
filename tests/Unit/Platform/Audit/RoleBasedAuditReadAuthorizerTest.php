<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Audit;

use App\Platform\Audit\Contracts\AuditReadAuthorizer;
use App\Platform\Audit\Exceptions\AuditReadNotAuthorisedException;
use App\Platform\Audit\RoleBasedAuditReadAuthorizer;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\RoleAuditActions;
use App\Platform\IdentityAccess\Scopes\ScopeAuditActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The audit-read policy in isolation. `AuditEventsResourceAccessTest`
 * (`tests/Feature/Filament/Admin/AuditEvents/`) covers the same policy
 * exercised through the whole live chain (grant row -> `ActorRoleReader` ->
 * `ActorContext::$roles` -> the Filament resource); this file pins the
 * decision itself, including cases that are awkward to reach over HTTP.
 *
 * A plain PHPUnit `TestCase`, not `Tests\TestCase`: the authorizer touches
 * no database, no container, and no request — same reasoning
 * `FinanceOrRestrictedAdminPaymentAuthorizerTest` gives for its own choice.
 */
final class RoleBasedAuditReadAuthorizerTest extends TestCase
{
    private function authorizer(): RoleBasedAuditReadAuthorizer
    {
        return new RoleBasedAuditReadAuthorizer;
    }

    public function test_it_implements_the_module_contract(): void
    {
        $this->assertInstanceOf(AuditReadAuthorizer::class, $this->authorizer());
    }

    public function test_a_guest_context_is_refused(): void
    {
        $this->expectException(AuditReadNotAuthorisedException::class);

        $this->authorizer()->authorize(ActorContext::guest());
    }

    public function test_a_null_identity_reference_is_refused_even_with_a_role(): void
    {
        $this->expectException(AuditReadNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: null, roles: [ActorRole::ADMIN]),
        );
    }

    public function test_an_empty_string_identity_reference_is_refused(): void
    {
        $this->expectException(AuditReadNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: '', roles: [ActorRole::ADMIN]),
        );
    }

    /**
     * An empty role list means "this actor holds no grants today", never "no
     * role required."
     */
    public function test_an_empty_role_list_is_refused(): void
    {
        $this->expectException(AuditReadNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: 1, roles: []),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function unauthorisedRoles(): iterable
    {
        // Every KNOWN_ROLES entry that is NOT on the allow-list. See
        // `RoleBasedAuditReadAuthorizer`'s own doc block for why `finance`
        // in particular is excluded despite its scoped grants elsewhere.
        yield 'finance' => [ActorRole::FINANCE];
        yield 'operator' => [ActorRole::OPERATOR];
        yield 'case_manager' => [ActorRole::CASE_MANAGER];
        yield 'vendor' => [ActorRole::VENDOR];
        yield 'customer' => [ActorRole::CUSTOMER];
        yield 'system' => [ActorRole::SYSTEM];
    }

    #[DataProvider('unauthorisedRoles')]
    public function test_every_role_other_than_admin_or_restricted_admin_is_refused(string $role): void
    {
        $this->expectException(AuditReadNotAuthorisedException::class);

        $this->authorizer()->authorize(
            new ActorContext(identityReference: 1, roles: [$role]),
        );
    }

    public function test_admin_receives_an_unrestricted_scope(): void
    {
        $scope = $this->authorizer()->authorize(
            new ActorContext(identityReference: 1, roles: [ActorRole::ADMIN]),
        );

        $this->assertSame(ActorRole::ADMIN, $scope->role);
        $this->assertSame([], $scope->excludedActions);
    }

    public function test_restricted_admin_receives_a_scope_excluding_privilege_escalation_actions(): void
    {
        $scope = $this->authorizer()->authorize(
            new ActorContext(identityReference: 1, roles: [ActorRole::RESTRICTED_ADMIN]),
        );

        $this->assertSame(ActorRole::RESTRICTED_ADMIN, $scope->role);
        $this->assertSame(
            [
                'MFA_RESET',
                RoleAuditActions::GRANT,
                RoleAuditActions::REVOKE,
                ScopeAuditActions::GRANT,
                ScopeAuditActions::REVOKE,
            ],
            $scope->excludedActions,
        );
    }

    /**
     * `ActorRole::KNOWN_ROLES` precedence order, most privileged first: an
     * actor holding both authorised roles is recorded, and scoped, under
     * `admin`, regardless of input order.
     */
    public function test_an_actor_holding_both_authorised_roles_is_scoped_as_admin(): void
    {
        $this->assertSame([], $this->authorizer()->authorize(
            new ActorContext(identityReference: 1, roles: [ActorRole::RESTRICTED_ADMIN, ActorRole::ADMIN]),
        )->excludedActions);

        $this->assertSame([], $this->authorizer()->authorize(
            new ActorContext(identityReference: 1, roles: [ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN]),
        )->excludedActions);
    }

    /**
     * `0` is a legitimate integer key shape, not an absent identity — refused
     * on emptiness, never falsiness.
     */
    public function test_a_zero_identity_reference_is_a_present_identity(): void
    {
        $scope = $this->authorizer()->authorize(
            new ActorContext(identityReference: 0, roles: [ActorRole::ADMIN]),
        );

        $this->assertSame(ActorRole::ADMIN, $scope->role);
    }
}
