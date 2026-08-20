<?php

declare(strict_types=1);

namespace App\Platform\Audit\Contracts;

use App\Platform\Audit\AuditReadScope;
use App\Platform\Audit\Exceptions\AuditReadNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;

/**
 * The policy seam for "may this actor READ the audit trail, and how much of
 * it" — ADM-100 (`.kiro/specs/admin-operations/requirements.md`) AC8
 * ("dedicated authorization and audit for sensitive actions") and AC10
 * ("scope export/report queries to the requesting admin's role and
 * business-entity permissions") applied to `audit_events`, the one table
 * every sensitive mutation across this whole platform writes to.
 *
 * ---------------------------------------------------------------------------
 * Why this is role-only, with no `scope_assignments` grant check
 * ---------------------------------------------------------------------------
 * Every scoped read authorizer elsewhere in this codebase
 * (`FinancialLedger\Contracts\LedgerReadAuthorizer`,
 * `FinancialLedger\FinanceOrRestrictedAdminPayoutAuthorizer`) pairs a role
 * test with an active `ScopeAssignment` lookup keyed on a real
 * `scope_assignments.entity_id` value the subject record carries (a badan
 * usaha, a vendor). `audit_events` has no such column and cannot get one
 * without inventing a mapping no consuming spec has stated: `subject_type`/
 * `subject_id` span every domain in this platform (cemetery, vendor, order,
 * case, grave, business entity, role assignment, MFA enrolment, …) with
 * heterogeneous key shapes, and `AuditSubject`'s own doc block says the
 * caller's `$type` string is "this batch does not mandate one scheme." A
 * scope check keyed on a value with no fixed vocabulary would not be a real
 * control.
 *
 * This is the same situation `Payment\FinanceOrRestrictedAdminPaymentAuthorizer`
 * documents for `payment_reversals`/`payment_verifications` — "nothing in
 * either is in the `scope_assignments.entity_id` value space" — and
 * `docs/security/rbac-matrix.md` names that authorizer as the deliberate,
 * narrow exception to its own "role and scope grant" rule. This contract is
 * the same exception, for the same reason, applied to a second table.
 *
 * `AuditReadScope::$excludedActions` is therefore the scoping mechanism this
 * module DOES have: an action-level cut over the closed
 * `SensitiveActions::ACTIONS`-adjacent vocabulary, decided by role, rather
 * than a business-entity cut. Business-entity scoping of audit review is left
 * an explicit gap for a future batch that defines a real subject-to-entity
 * mapping — not approximated here.
 *
 * ---------------------------------------------------------------------------
 * Contract terms an implementation must keep
 * ---------------------------------------------------------------------------
 *  1. The reading actor is derived from the authenticated server-side
 *     `ActorContext`. A caller-supplied actor reference or role must never
 *     select an actor or grant a role.
 *  2. An empty role list is not permission. Absence of information fails
 *     closed, never open.
 *  3. `AuditReadScope::$excludedActions` narrows what the returned scope may
 *     see; it is never widened by a caller-supplied parameter, because this
 *     contract accepts none.
 */
interface AuditReadAuthorizer
{
    /**
     * Resolve the permitted read scope for the server-resolved actor, or
     * refuse.
     *
     * @throws AuditReadNotAuthorisedException
     */
    public function authorize(ActorContext $actor): AuditReadScope;
}
