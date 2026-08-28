<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Roles;

use InvalidArgumentException;

/**
 * The closed list of roles `actor_role_assignments.role` may hold — the
 * `platform-identity-seam` design doc's decision 1: "Role vocabulary is
 * discovered, not invented."
 *
 * Every entry here is the union of roles already load-bearing in shipped
 * code plus `docs/security/rbac-matrix.md`'s own column set — nothing on
 * this list is new nomenclature:
 *
 * - `admin` — `DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS`.
 * - `restricted_admin` —
 *   `FinanceOrRestrictedAdminPayoutAuthorizer::RESTRICTED_ADMIN_ROLE`.
 * - `finance` — `FinanceLedgerReadAuthorizer::FINANCE_ROLE`,
 *   `FinanceReconciliationAuthorizer`, `FinanceVendorPayableAuthorizer
 *   ::FINANCE_ROLE`, `FinanceOrRestrictedAdminPayoutAuthorizer::FINANCE_ROLE`.
 * - `operator` — `DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS`.
 * - `case_manager` — `DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS`.
 * - `vendor` — `docs/security/rbac-matrix.md` column; no code consumer yet.
 * - `cemetery_operator` — `docs/superpowers/plans/2026-08-28-operator-panel-and-role.md`
 *   (this plan); no code consumer until this same plan's later tasks land.
 * - `customer` — `DocumentAccessPolicy::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS`.
 * - `system` — `FinanceVendorPayableAuthorizer::SYSTEM_ROLE`, the value
 *   recorded for a genuinely unattended (no human actor) run.
 *
 * `issuer` and `auditor` are deliberately EXCLUDED. `rbac-matrix.md` mentions
 * both, but folds them into one column alongside finance and no shipped code
 * consumes either as a distinct role. The list is one-line-extensible (same
 * shape as `Scopes\ScopeEntityType::KNOWN_TYPES`), so adding them once a real
 * consumer exists is an application-layer change, not a migration.
 *
 * `KNOWN_ROLES`' declaration order IS precedence order, most privileged
 * first. Consumers such as `DocumentAccessPolicy::auditRoleFor()` walk a
 * role list to pick the single most-privileged match for an audit row, and
 * `Roles\ActorRoleReader::rolesForActor()` (a later batch) sorts its result
 * against this same order rather than trusting database insertion order —
 * this array is the one place that ordering is decided.
 *
 * ---------------------------------------------------------------------------
 * `guest` and `authenticated_actor` must NEVER be added here
 * ---------------------------------------------------------------------------
 * Both are audit sentinels, not roles — `DocumentAccessPolicy:124,130` uses
 * them to mean "no role applies" (an unauthenticated actor, or an
 * authenticated actor holding none of the roles a policy recognises). If
 * either became a grantable, known role, an `actor_role_assignments` row
 * could claim an actor holds a role that the authorization layer reads as
 * that actor's absence of any role — a privilege-escalation-shaped
 * inconsistency between the grant table and the policies that read it.
 * `assertKnown()` rejects both by construction; `ActorRoleTest` pins this
 * explicitly.
 *
 * Deliberately a plain string column at the schema level (see the
 * migration's own doc block), validated against this list at the
 * application layer instead — closed lists in this codebase are
 * application-layer PHP constants, never Postgres enum types, so extending
 * one never requires a migration. Same pattern, same reasoning, as
 * `Scopes\ScopeEntityType`.
 */
final class ActorRole
{
    public const string ADMIN = 'admin';

    public const string RESTRICTED_ADMIN = 'restricted_admin';

    public const string FINANCE = 'finance';

    public const string OPERATOR = 'operator';

    public const string CASE_MANAGER = 'case_manager';

    public const string VENDOR = 'vendor';

    /**
     * The TPU/TPS operator dashboard roadmap's Phase A role
     * (`/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`, "Role &
     * scoping"). Structurally identical to `VENDOR`: an actor acting for one
     * or more specific cemeteries via a `scope_assignments` grant of
     * `Scopes\ScopeEntityType::CEMETERY`, never platform-wide. First real
     * consumers land in this same plan: `Panel\CemeteryOperatorPanelAccessPolicy`
     * (the `/operator` panel's access check) and
     * `OrderWorkflow\Authorization\OrderTransitionAuthorizer`'s
     * cemetery-scoped branch.
     */
    public const string CEMETERY_OPERATOR = 'cemetery_operator';

    public const string CUSTOMER = 'customer';

    public const string SYSTEM = 'system';

    /**
     * Declaration order is precedence order, most privileged first — see
     * the class-level doc block. Later readers of `actor_role_assignments`
     * must sort against this array rather than relying on database
     * insertion order.
     *
     * @var list<string>
     */
    public const array KNOWN_ROLES = [
        self::ADMIN,
        self::RESTRICTED_ADMIN,
        self::FINANCE,
        self::OPERATOR,
        self::CASE_MANAGER,
        self::VENDOR,
        self::CEMETERY_OPERATOR,
        self::CUSTOMER,
        self::SYSTEM,
    ];

    public static function isKnown(string $role): bool
    {
        return in_array($role, self::KNOWN_ROLES, true);
    }

    /**
     * @throws InvalidArgumentException when `$role` is not one of
     *                                  `self::KNOWN_ROLES` — including the
     *                                  `guest` / `authenticated_actor`
     *                                  sentinels, which are never known.
     */
    public static function assertKnown(string $role): void
    {
        if (! self::isKnown($role)) {
            throw new InvalidArgumentException(
                "Unknown actor role [{$role}]. Known roles: ".implode(', ', self::KNOWN_ROLES).'.'
            );
        }
    }
}
