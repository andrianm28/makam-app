# Wave 1b Financial Decisions

Approved by the product/finance/security owner on 10 Aug 2026 during takeover
of the Wave 1 lane work.

## Vendor payout accounting

Add the vendor-liability account `2100` (`Liabilitas — Utang Vendor`, normal
balance `CR`) to the chart of accounts. Vendor-payable assessment records the
accrual as `DR 5000 HPP / Komisi Vendor` and `CR 2100 Utang Vendor`. Manual
payout records `DR 2100 Utang Vendor` and `CR 7000 Rekening Kas/Bank`.
Assessment and payout remain separate workflow transitions; payout must not
touch the customer's original journal rows.

## Payout authorization

The approver is derived from the authenticated server-side `ActorContext`.
Caller-supplied approver references or roles cannot select another actor.
Payout authorization must be an explicit restricted-admin or finance policy,
not merely a caller-provided role or generic privileged scope.

## Payout proof

Payout proof must reference an accepted, private DocumentVault document of the
approved proof kind and be record-scoped. The payout module stores only the
opaque document reference; it never copies document content or private data.

## Reversal audit

Add `JOURNAL_REVERSAL` to the sensitive action list and audit every reversal
creation with a mandatory reason. This closes the inherited reversal-audit gap
before the financial-ledger branch can merge.

## Uniqueness

Keep the database uniqueness policies: at most one reversal per original batch
and at most one payout per payable. Retries are idempotent and do not create a
second financial correction or settlement.

## Reconciliation-exception audit (Wave 1c, approved 10 Aug 2026)

Add `RECONCILIATION_EXCEPTION_RESOLVED` to `SensitiveActions::ACTIONS` inside
Task 5, in the same commit as `ResolveException`, with the mandatory reason
enforced.

This ruling resolves a contradiction inside the plan document itself. The
plan's Global Constraints enumerate this lane's growth of that array as a
single entry (`PRICE_VERSION_RECORDED`, with L3 separately adding
`PAYMENT_REFUND`/`PAYMENT_CHARGEBACK`), while the plan's own Task 5 text
requires `Audit::record('RECONCILIATION_EXCEPTION_RESOLVED', reason mandatory,
sensitive)` — which is only expressible by growing that array again. Task 5
could not be implemented as written without violating the plan's own
constraints, so the question was escalated rather than decided lane-locally.

Rationale for resolving it this way rather than deferring to Task 6: it
follows the `JOURNAL_REVERSAL` precedent; deferring the audit action is
precisely what produced this lane's Task 3 reversal-audit gap, where a
financial correction path shipped with no audit trail and had to be chased
down two tasks later; and an exception resolution that changes a
reconciliation outcome without a recorded reason is exactly the control this
list exists to enforce. Verified at ruling time: L3's `SensitiveActions.php`
is still untouched, so no real cross-lane collision exists.

Both `SensitiveActions` deviations from the originally approved sequencing —
`JOURNAL_REVERSAL` (landed in Task 4 rather than Task 6) and
`RECONCILIATION_EXCEPTION_RESOLVED` (added in Task 5, beyond the single
addition the Global Constraints anticipated) — must be named explicitly in
this lane's PR description at Task 10.

These decisions do not waive the required human review before financial,
authorization, privacy, or security changes merge.
