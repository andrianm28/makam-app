<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * The server-derived answer to "which audit events may this actor read, and
 * under which role" — the return value of
 * `Contracts\AuditReadAuthorizer::authorize()`.
 *
 * `$excludedActions` is the QUERY-LEVEL SCOPE `AGENTS.md` §Authorization
 * makes mandatory, applied the only way this table supports it — see
 * `Contracts\AuditReadAuthorizer`'s own doc block for why that is an
 * action-level cut rather than a business-entity one. Every audit-event read
 * mounted behind this authorizer filters `action NOT IN ($excludedActions)`,
 * so there is no code path that shows a scoped actor an action their role was
 * refused.
 *
 * An empty `$excludedActions` list is a legitimate result — it means "no
 * action is withheld from this role" (the unrestricted `admin` case), not
 * "the scope failed to compute." A refusal is a thrown
 * `Exceptions\AuditReadNotAuthorisedException`, never an all-actions-excluded
 * scope that would silently render an empty table instead of a 403.
 */
final readonly class AuditReadScope
{
    /**
     * @param  string  $role  The role that granted the read, for the audit
     *                        row and UI — server-derived, never
     *                        caller-supplied.
     * @param  list<string>  $excludedActions  Actions this scope may NOT see.
     *                                         Sorted, duplicate-free. Empty
     *                                         means unrestricted.
     */
    public function __construct(
        public string $role,
        public array $excludedActions,
    ) {}
}
