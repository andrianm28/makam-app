# Tasks — Platform Financial Ledger and Settlement

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Define `journal_batches` / `journal_entries` with integer minor-unit amounts. _Requirements: 1, 11_
- [ ] Enforce debit=credit at the **database** level so no application path can bypass it. _Requirements: 1_
- [ ] Enforce append-only: no update or delete grant on journal tables for the app role. _Requirements: 2_
- [ ] Implement unique `business_key` idempotency and document the key format per source. _Requirements: 5_
- [ ] Write journal batches in the same transaction as the causing state change. _Requirements: 3_
- [ ] Bind every batch to an explicit `badan_usaha` / merchant entity. _Requirements: 4_
- [ ] Implement reversal-plus-correction flow; never edit a posted batch. _Requirements: 2_
- [ ] Implement vendor payable eligibility as a rule separate from paid state. _Requirements: 8_
- [ ] Implement manual payout with amount, proof, approver, and reference while `G-PAYOUT-01` is closed. _Requirements: 9_
- [ ] Implement reconciliation against provider statements producing exceptions, not adjustments. _Requirements: 10_
- [ ] Require authorized decision plus audit to resolve each exception. _Requirements: 10_
- [ ] Require recent re-authentication for bulk financial export. _Requirements: 13_
- [ ] Add tests: unbalanced batch rejected; duplicate business key posts once. _Requirements: 1, 5_
- [ ] Add tests: partial refund allocation; chargeback; payable hold and release. _Requirements: 7, 8_
- [ ] Add tests: reports reconcile to journal, not to order status. _Requirements: 6, 12_
- [ ] Add tests: release rollback preserves journal and audit history. _Requirements: 14_

## Design system

Financial UI lives in `admin-operations` (ADM-070, ADM-090) and the vendor portal, but the **presentation contract** is owned here. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- All money uses `text-right tabular-nums` and `--font-mono`; totals `--font-weight-bold`. Never render a float.
- Paid, payable, and paid-out are **three separate indicators** via `<x-mk.badge>` §3.6 — never merged into one "settled" badge.
- Reconciliation exceptions use `intent=danger` when they affect a customer balance and `intent=pending` when they are awaiting a decision; an exception is **never** rendered as resolved because a period closed.
- Reversal and payout confirmations use `<x-mk.modal>` §3.4: consequence stated, reason captured, destructive option not default-focused, recent re-authentication required.
- Bulk export renders as `variant=secondary`, never `primary`, and never adjacent to a benign action (§3.5).
- Required states per §6: loading · empty ("Belum ada transaksi pada periode ini") · validation error · authorization failure (finance-scoped, no existence leak) · provider unavailable (statement fetch failed → say so, never show a partial period as complete) · duplicate-safe · pending · success (quiet) · support · responsive.

## NOT TESTED

Nothing here is implemented. The **FIN-DEC approvals required by `release-gates.md` §H are not granted**, and `G-PAY-01` / `G-PAYOUT-01` states are unknown, so this spec is gate-blocked. `financial-ledger-and-settlement.md` and `financial-model.md` exist but have **not** been reconciled against these criteria account by account, and no chart of accounts has been defined — that is a prerequisite and an open question for the finance owner.
