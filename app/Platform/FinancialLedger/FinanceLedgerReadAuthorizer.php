<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;

/**
 * Explicit ledger-read policy: a real `finance` role from the server-side
 * actor context, plus at least one active privileged `BUSINESS_ENTITY` grant —
 * and the read is scoped to exactly those granted badan usaha.
 *
 * ---------------------------------------------------------------------------
 * Deliberately identical in shape to `FinanceReconciliationAuthorizer`
 * ---------------------------------------------------------------------------
 * Same role (`finance` only, not `restricted_admin`), same
 * `ScopeEntityType::BUSINESS_ENTITY` + `ScopeGrantLevel::PRIVILEGED` +
 * `revoked_at IS NULL` grant test, over the same `entity_ref` id space. That is
 * not accidental duplication: reading a badan usaha's books and deciding a
 * variance on those same books are the same authority over the same records,
 * and `docs/security/rbac-matrix.md` names no separate reporting role. The two
 * classes stay separate because they are separate PERMISSIONS that happen to
 * resolve the same way today — merging them would mean a later widening of the
 * decision policy silently widened the read policy too.
 *
 * ---------------------------------------------------------------------------
 * The set, not the wildcard
 * ---------------------------------------------------------------------------
 * The defect this class closes is that the export accepted `entityRef` as an
 * optional query-string parameter and, when it was omitted, produced a
 * cross-entity export labelled `all`. Here, omitting it means "every badan
 * usaha I hold a grant for" — a set the server derives, never a wildcard. An
 * actor with one grant gets a one-entity report; an actor with none is
 * refused outright rather than falling through to an unfiltered query.
 *
 * A supplied `entityRef` may only narrow that set. It is checked against the
 * grants rather than trusted, which also means the value that reaches the
 * audit row and the report label is always a real `scope_assignments.entity_id`
 * this deployment issued — never unvalidated caller text.
 *
 * ---------------------------------------------------------------------------
 * It fails closed, and that is the correct state
 * ---------------------------------------------------------------------------
 * `ActorContext::$roles` now resolves real granted roles via
 * `actor_role_assignments` (merged in commit `3dbdcde`, lane L5), so this
 * policy is genuinely enforcing rather than statically refusing: an actor
 * holding `finance` plus at least one active privileged `BUSINESS_ENTITY`
 * grant is authorised, and one holding neither is still refused. For a bulk
 * cross-entity financial read that is the right answer, and it is exactly how
 * `ResolveException` and `ManualPayout` already behave.
 */
final class FinanceLedgerReadAuthorizer implements LedgerReadAuthorizer
{
    public const string FINANCE_ROLE = 'finance';

    /**
     * @var list<string>
     */
    private const array AUTHORISED_ROLES = [
        self::FINANCE_ROLE,
    ];

    public function authorize(ActorContext $actor, ?string $entityRef = null): LedgerReadScope
    {
        $actorReference = $actor->identityReference;

        if ($actorReference === null) {
            throw LedgerReadNotAuthorisedException::forActorContext();
        }

        $role = $this->roleFromContext($actor);

        if ($role === null) {
            throw LedgerReadNotAuthorisedException::forActorContext();
        }

        $granted = ScopeAssignment::query()
            ->where('actor_identifier', (string) $actorReference)
            ->where('entity_type', ScopeEntityType::BUSINESS_ENTITY)
            ->where('grant_level', ScopeGrantLevel::PRIVILEGED)
            ->whereNull('revoked_at')
            ->when(
                $entityRef !== null,
                static fn ($query) => $query->where('entity_id', $entityRef),
            )
            ->orderBy('entity_id')
            ->pluck('entity_id')
            ->map(static fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($granted === []) {
            throw LedgerReadNotAuthorisedException::forActorContext();
        }

        // Sorted again in PHP, deliberately, and not as belt-and-braces
        // duplication of the `orderBy` above.
        //
        // Callers depend on this order for more than presentation:
        // `Actions\BulkFinancialExport` digests the joined list into the audit
        // subject reference when the scope outgrows `audit_events.subject_id`,
        // so an unstable order silently produces a different reference for the
        // same set and two identical exports stop correlating.
        //
        // Leaving that guarantee to the database makes it incidental rather
        // than owned. `ORDER BY` is honoured, but the resulting collation is
        // the server's (locale-aware on PostgreSQL, BINARY on SQLite), so the
        // same grant set can order differently across drivers or deployments.
        // `sort()` is byte-wise and identical everywhere, which is exactly the
        // property a digest needs. Verified by execution rather than assumed:
        // dropping the `orderBy` alone did NOT make the ordering test fail,
        // because the planner satisfied the query from
        // `scope_assignments_entity_idx` on `(entity_type, entity_id)` and
        // handed back entity-sorted rows anyway. That is a planner accident
        // that a different row count or index choice can withdraw at any time —
        // precisely the kind of guarantee that must not live in the query plan.
        sort($granted);

        return new LedgerReadScope(role: $role, entityRefs: $granted);
    }

    private function roleFromContext(ActorContext $actor): ?string
    {
        foreach (self::AUTHORISED_ROLES as $role) {
            if (in_array($role, $actor->roles, true)) {
                return $role;
            }
        }

        return null;
    }
}
