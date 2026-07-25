# Requirements — Platform Financial Ledger and Settlement

**Authority:** K3–K5 shared money path; ADR-0020; `docs/domain/financial-ledger-and-settlement.md`; `docs/domain/financial-model.md`; `AGENTS.md` §Domain and financial invariants; `release-gates.md` §H.

**Status:** Foundation P0. Referenced by 5 specs, owned by none — `docs/planning/kiro-specs-analysis.md` §2.2. Gated by the FIN-DEC approvals in `release-gates.md` §H.

## Acceptance criteria

1. Every financial effect is recorded as a **balanced** journal batch. Debits equal credits, enforced at write time, not by a later report.
2. The ledger is **append-only**. A correction is a new reversing batch that references the original; history is never edited or deleted.
3. Journal batches are written in the same database transaction as the state change that causes them.
4. Every batch is bound to an explicit `badan_usaha` / merchant entity.
5. Journal writes are idempotent by business key so a retried webhook or job cannot double-post.
6. Order status is **not** the financial source of truth. Reports and totals reconcile to journal references, never to mutable order state.
7. Refund, chargeback, vendor payable, and payout are distinct operation types with their own authorization, journal shape, and audit.
8. Vendor payable eligibility is explicit: paid does not imply payable, and payable does not imply paid out.
9. While `G-PAYOUT-01` is closed, payout is manual: amount, proof, approver, and reference are recorded, and no automated transfer occurs.
10. Reconciliation compares platform journal against provider settlement records, produces exceptions rather than silent adjustments, and requires an authorized decision to resolve each exception.
11. Currency and rounding rules are explicit and applied consistently; no floating-point money.
12. Financial reports declare their period, source, and generation time, and are reproducible from the ledger.
13. Bulk financial export requires recent re-authentication and is audited.
14. Rollback of an application release never deletes journal or audit history.

## Negative criteria

- No unbalanced journal batch accepted.
- No edit or delete of a posted entry.
- No financial total derived only from order status.
- No automated payout while `G-PAYOUT-01` is closed.
- No reconciliation exception auto-resolved without an authorized decision.
- No money value stored as a float.
