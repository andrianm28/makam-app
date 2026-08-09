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

These decisions do not waive the required human review before financial,
authorization, privacy, or security changes merge.
