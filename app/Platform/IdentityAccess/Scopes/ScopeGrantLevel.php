<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes;

use InvalidArgumentException;

/**
 * Optional grant-level metadata for a `scope_assignments` row.
 *
 * ---------------------------------------------------------------------------
 * Why this exists, and why the global scope mechanism does NOT read it
 * ---------------------------------------------------------------------------
 * `docs/security/rbac-matrix.md` distinguishes "Own" (customer/family),
 * "Assigned" (case manager / operator), and a bare "Yes"/"Privileged" for
 * roles with broader access. Those distinctions are about *capability*
 * (what an actor may DO with a record it can already see — edit, override,
 * approve) not about *visibility* (whether the record is in the actor's
 * world at all).
 *
 * `platform-identity-and-access` design.md lists "Mandatory query scope"
 * and "Policy check per model action" as two SEPARATE enforcement points —
 * this batch (`Scopes/`) owns only the first one. Query-level scope's job
 * is exactly "is this row even reachable by this actor", which is a binary
 * question: an active, non-revoked `scope_assignments` row is sufficient
 * for `ScopeAssignmentGlobalScope` to include a record in query results.
 * Whether an "own" grant permits an edit that an "assigned" grant does not
 * is a Policy-layer decision (design.md's enforcement point 2), which no
 * file in this batch implements or is authorized to implement.
 *
 * `grant_level` is carried on the row anyway — nullable, validated when
 * present — purely as forward-compatible metadata so a future Policy class
 * can read it without a schema change. `ScopeAssignmentGlobalScope`
 * deliberately ignores it.
 */
final class ScopeGrantLevel
{
    /**
     * The actor is the record's own principal (e.g. a customer/family's own
     * booking) — rbac-matrix.md's "Own".
     */
    public const string OWN = 'own';

    /**
     * The actor has been explicitly assigned to the record (e.g. a case
     * manager's assigned cases, an operator's assigned cemetery) —
     * rbac-matrix.md's "Assigned".
     */
    public const string ASSIGNED = 'assigned';

    /**
     * Read-only visibility without assignment or ownership (e.g. an
     * auditor's read/audit subset) — rbac-matrix.md's "Read"/"Audit" rows.
     */
    public const string READ = 'read';

    /**
     * Broad, role-driven access that is still expressed as an explicit
     * grant row rather than an implicit role bypass — rbac-matrix.md's
     * "Yes"/"Privileged" for admin-class roles.
     */
    public const string PRIVILEGED = 'privileged';

    /**
     * @var list<string>
     */
    public const array KNOWN_LEVELS = [
        self::OWN,
        self::ASSIGNED,
        self::READ,
        self::PRIVILEGED,
    ];

    public static function isKnown(string $level): bool
    {
        return in_array($level, self::KNOWN_LEVELS, true);
    }

    /**
     * @throws InvalidArgumentException when `$level` is not one of
     *                                  `self::KNOWN_LEVELS`.
     */
    public static function assertKnown(string $level): void
    {
        if (! self::isKnown($level)) {
            throw new InvalidArgumentException(
                "Unknown scope grant level [{$level}]. Known levels: ".implode(', ', self::KNOWN_LEVELS).'.'
            );
        }
    }
}
