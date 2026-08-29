<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Roles;

use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\FinanceOrRestrictedAdminPayoutAuthorizer;
use App\Platform\FinancialLedger\FinanceVendorPayableAuthorizer;
use App\Platform\IdentityAccess\Roles\ActorRole;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pure unit coverage — no database, no container. `ActorRole` is the
 * application-level validation `actor_role_assignments.role` is enforced
 * through, since the schema column itself is a plain string (see the
 * migration's doc block).
 */
final class ActorRoleTest extends TestCase
{
    public function test_known_roles_is_the_ruled_closed_list_in_precedence_order(): void
    {
        $this->assertSame([
            'admin', 'restricted_admin', 'finance', 'operator',
            'case_manager', 'vendor', 'cemetery_operator', 'customer', 'system',
        ], ActorRole::KNOWN_ROLES);
    }

    public function test_known_roles_list_has_exactly_nine_entries(): void
    {
        // Locks the count in explicitly so an accidental duplicate or a
        // silently dropped entry fails loudly here.
        $this->assertCount(9, ActorRole::KNOWN_ROLES);
    }

    public function test_guest_sentinel_is_never_a_grantable_role(): void
    {
        // 'guest' is an audit sentinel (DocumentAccessPolicy:130) meaning
        // "no role applies" (unauthenticated actor). If it were grantable,
        // an audit row could claim a role the authorization layer reads as
        // its absence.
        $this->assertFalse(ActorRole::isKnown('guest'));

        $this->expectException(InvalidArgumentException::class);
        ActorRole::assertKnown('guest');
    }

    public function test_authenticated_actor_sentinel_is_never_a_grantable_role(): void
    {
        // 'authenticated_actor' is an audit sentinel (DocumentAccessPolicy:124)
        // meaning "no role applies" (authenticated actor holding none of the
        // roles a policy recognises). Same reasoning as the guest sentinel
        // above, split into its own test because `expectException` inside a
        // loop would stop at the first iteration and never really exercise
        // this value.
        $this->assertFalse(ActorRole::isKnown('authenticated_actor'));

        $this->expectException(InvalidArgumentException::class);
        ActorRole::assertKnown('authenticated_actor');
    }

    public function test_every_role_consumed_by_shipped_code_is_present(): void
    {
        // Regression guard: these literals are already checked by shipped
        // authorizers. Dropping one silently re-breaks that consumer.
        foreach ([
            FinanceLedgerReadAuthorizer::FINANCE_ROLE,
            FinanceOrRestrictedAdminPayoutAuthorizer::RESTRICTED_ADMIN_ROLE,
            FinanceVendorPayableAuthorizer::SYSTEM_ROLE,
        ] as $role) {
            $this->assertTrue(ActorRole::isKnown($role));
        }
    }

    public function test_assert_known_does_not_throw_for_a_known_role(): void
    {
        ActorRole::assertKnown(ActorRole::FINANCE);

        $this->addToAssertionCount(1);
    }

    public function test_assert_known_throws_for_an_unknown_role(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ActorRole::assertKnown('wizard');
    }
}
