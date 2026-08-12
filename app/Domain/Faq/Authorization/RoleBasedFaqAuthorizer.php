<?php

declare(strict_types=1);

namespace App\Domain\Faq\Authorization;

use App\Domain\Faq\Contracts\FaqAuthorizer;
use App\Domain\Faq\Exceptions\FaqActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;

/**
 * `admin` — and only `admin` — may manage FAQ content.
 *
 * ---------------------------------------------------------------------------
 * Why exactly one role, and why that is a decision rather than an oversight
 * ---------------------------------------------------------------------------
 * `docs/security/rbac-matrix.md` has no FAQ row and no content-management row
 * at all, so the authoritative answer to "which roles may edit the FAQ?" does
 * not exist yet. Faced with an unspecified boundary, this class takes the most
 * restrictive defensible position: the one role whose name already means
 * "unrestricted platform administration" in
 * `Roles\ActorRole`'s closed list, which is also that list's most-privileged
 * entry.
 *
 * That direction is deliberate and asymmetric. Granting too narrowly produces
 * a support ticket; granting too widely produces an unnoticed authorization
 * defect that no test will ever fail on. Widening this list later is a
 * one-line, reviewable change with a visible diff and a named approver;
 * narrowing it after roles have been handed out is a migration and a
 * conversation. `restricted_admin`, `operator`, and `case_manager` are all
 * plausible future entries — none is added here, because "plausible" is not
 * the standard an authorization boundary is held to.
 *
 * Not a permission abstraction, on purpose: nothing in this codebase models
 * permissions as anything other than roles read off `ActorContext`
 * (`FinanceLedgerReadAuthorizer`, `FinanceOrRestrictedAdminPayoutAuthorizer`,
 * `DocumentAccessPolicy`). Inventing a `faq.manage` permission vocabulary for
 * one module would be a new, unbacked concept with no grant path — there is no
 * `permission_assignments` table and no write action that could populate one.
 *
 * ---------------------------------------------------------------------------
 * Fail-closed, and honest about what an empty role list means
 * ---------------------------------------------------------------------------
 * `ActorContext::$roles` is populated for real from `actor_role_assignments`
 * via `Roles\ActorRoleReader` (lane L5). An empty list therefore means "this
 * actor holds no role grants" — a legitimate, common, and REFUSED state, never
 * "no role required". `ActorContext::hasRole()` is `false` for a guest for the
 * same reason (a guest context carries no roles), so the authenticated check is
 * subsumed rather than duplicated here.
 *
 * Stateless by construction: the `ActorContext` under judgement arrives as a
 * method parameter, freshly, per call — never captured in a constructor
 * property. That is what makes the transient `bind()` in
 * `Providers\FaqServiceProvider` safe, and it is the same shape the four
 * `FinancialLedger` authorizers use for the same reason (see
 * `FinancialLedgerServiceProvider`'s doc block on the stale-actor hazard in a
 * long-lived Horizon worker).
 */
final class RoleBasedFaqAuthorizer implements FaqAuthorizer
{
    /**
     * The closed list of roles that may manage FAQ content. See the
     * class-level doc block before adding to it — a widening here is an
     * authorization change and carries `AGENTS.md` §Infrastructure-agent
     * execution's mandatory-human-review bar.
     *
     * @var list<string>
     */
    private const array ROLES_WITH_FAQ_MANAGEMENT = [
        ActorRole::ADMIN,
    ];

    public function canManage(ActorContext $actor): bool
    {
        foreach (self::ROLES_WITH_FAQ_MANAGEMENT as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function authorizeManage(ActorContext $actor): void
    {
        if (! $this->canManage($actor)) {
            throw FaqActionNotAuthorisedException::forActorContext();
        }
    }
}
