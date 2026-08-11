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
 * It fails closed today, and that is the correct state
 * ---------------------------------------------------------------------------
 * `ActorContext::$roles` is always `[]` under the current local identity
 * adapter (see that class's own doc block), so this policy refuses every real
 * request until that seam is backed by an authoritative role source. For a
 * bulk cross-entity financial read that is the right answer, and it is exactly
 * how `ResolveException` and `ManualPayout` already behave.
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
