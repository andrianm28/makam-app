<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\PanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;

/**
 * Explicit access check for the `/admin` Filament panel (Batch 2.4;
 * `app/Providers/Filament/AdminPanelProvider.php`, `->authMiddleware([
 * Authenticate::class])`).
 *
 * `docs/security/authentication-and-mfa.md` §7 documents the intended rule
 * as "`/admin` platform admin/finance/support by permission". Batch 2.4 could
 * not implement that literally, because `ActorContext::$roles` was empty for
 * every actor at the time — no local roles table existed and K1/K2 role
 * mapping (AC12) was unresolved — so a role check would have either locked
 * every user out or required silently inventing a fake role source.
 *
 * THAT REASON HAS EXPIRED, and this doc block used to claim otherwise.
 * `$roles` is populated for real as of lane L5: active, non-revoked
 * `actor_role_assignments` rows resolved through `Roles\ActorRoleReader` by
 * `Adapters\LocalUsersTableIdentityAccessAdapter` (see `ActorContext`'s own
 * class doc block). A role check here is therefore implementable today, and
 * this class DOES make it — see `allows()` below.
 *
 * ---------------------------------------------------------------------------
 * The rule
 * ---------------------------------------------------------------------------
 * `allows()` admits exactly the four panel roles — `admin`, `restricted_admin`,
 * `operator`, `finance` — and only them, and only for an authenticated actor.
 * A guest can never pass on role absence alone, and a `customer`-role (or
 * roleless) authenticated user is refused at the panel boundary before any
 * Resource is ever reached. The role list is an application-layer constant
 * exactly like every closed list in this codebase (`ActorRole::KNOWN_ROLES`,
 * `ScopeEntityType::KNOWN_TYPES`): widening it is a one-line, reviewable,
 * authorization change, not a migration.
 *
 * This rule was chosen by explicit user ruling (12 Aug 2026) as the L9
 * `admin-operations` lane's Task 10. It is deliberately the four roles named
 * above — the roles that already mean "can do back-office work" in
 * `docs/security/rbac-matrix.md` — and deliberately not `customer`, `vendor`,
 * or `case_manager`, whose panel access would need its own ruling.
 *
 * ---------------------------------------------------------------------------
 * Panel membership is not record access
 * ---------------------------------------------------------------------------
 * Individual admin Resources make their own decisions on top of this one;
 * this predicate never implies a record-level grant. The FAQ Resource is the
 * first to do so (`App\Domain\Faq\Contracts\FaqAuthorizer`, `admin` only) —
 * a panel-role actor who is not `admin` reaches `/admin` but is refused that
 * Resource, which is exactly the layering AC4 requires with "SHALL NOT grant
 * record access on panel membership alone".
 */
final class AdminPanelAccessPolicy implements PanelAccessPolicy
{
    /**
     * The closed list of roles that may enter `/admin`. See the class-level
     * doc block before adding to it — a widening here is an authorization
     * change and carries `AGENTS.md` §Infrastructure-agent execution's
     * mandatory-human-review bar.
     *
     * @var list<string>
     */
    private const array PANEL_ROLES = [
        ActorRole::ADMIN,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::OPERATOR,
        ActorRole::FINANCE,
    ];

    public function allows(ActorContext $actor): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        foreach (self::PANEL_ROLES as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
