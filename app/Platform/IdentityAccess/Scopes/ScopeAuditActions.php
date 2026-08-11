<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Scopes;

/**
 * The audit action names `Actions\GrantScopeAssignment` /
 * `Actions\RevokeScopeAssignment` write — the `platform-identity-seam`
 * design doc's decision 5. Structural twin of `Roles\RoleAuditActions`; see
 * that class's own doc block for the shared reasoning.
 *
 * Both values are on `SensitiveActions::ACTIONS` — a scope grant decides
 * which records an actor can even see (`ScopeAssignmentGlobalScope`'s query
 * boundary), the same access-control category as a role grant.
 */
final class ScopeAuditActions
{
    /**
     * Written by `Actions\GrantScopeAssignment` with `AuditOutcome::Allowed`,
     * subject = the new `ScopeAssignment` row.
     */
    public const string GRANT = 'SCOPE_GRANT';

    /**
     * Written by `Actions\RevokeScopeAssignment` with
     * `AuditOutcome::Allowed`. Revocation can affect more than one row, so
     * the subject identifies the actor/entity_type/entity_id triple rather
     * than a single row id — see that Action's own doc block.
     */
    public const string REVOKE = 'SCOPE_REVOKE';
}
