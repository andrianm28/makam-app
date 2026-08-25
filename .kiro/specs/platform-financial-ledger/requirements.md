# Requirements — Platform Financial Ledger and Settlement

**Authority:** K3–K5 shared money path; ADR-0020; `docs/domain/financial-ledger-and-settlement.md`; `docs/domain/financial-model.md`; `AGENTS.md` §Domain and financial invariants; `release-gates.md` §H.

**Status:** Foundation P0. Referenced by 5 specs, owned by none — `docs/planning/kiro-specs-analysis.md` §2.2. Gated by the FIN-DEC approvals in `release-gates.md` §H.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. WHEN a financial effect occurs THE SYSTEM SHALL record it as a **balanced** journal batch, with debits equal to credits enforced at write time, not by a later report.
2. THE SYSTEM SHALL keep the ledger **append-only**: WHEN a correction is needed THE SYSTEM SHALL post a new reversing batch that references the original, and THE SYSTEM SHALL NOT edit or delete history.
3. WHEN a state change causes a financial effect THE SYSTEM SHALL write its journal batch in the same database transaction as that state change.
4. THE SYSTEM SHALL bind every journal batch to an explicit `badan_usaha` / merchant entity.
5. WHEN a webhook or job is retried THE SYSTEM SHALL apply idempotent journal writes by business key so it cannot double-post.
6. THE SYSTEM SHALL NOT treat order status as the financial source of truth; THE SYSTEM SHALL reconcile reports and totals to journal references, never to mutable order state.
7. THE SYSTEM SHALL implement refund, chargeback, vendor payable, and payout as distinct operation types, each with its own authorization, journal shape, and audit.
8. THE SYSTEM SHALL determine vendor payable eligibility explicitly; a paid state SHALL NOT imply payable, and a payable state SHALL NOT imply paid out.
9. WHILE `G-PAYOUT-01` is closed THE SYSTEM SHALL require manual payout — recording amount, proof, approver, and reference — and THE SYSTEM SHALL NOT perform an automated transfer.
10. WHEN reconciliation runs THE SYSTEM SHALL compare the platform journal against provider settlement records and produce exceptions rather than silent adjustments; THE SYSTEM SHALL require an authorized decision to resolve each exception.
11. THE SYSTEM SHALL apply explicit, consistent currency and rounding rules; THE SYSTEM SHALL NOT store a money value as a floating-point number.
12. THE SYSTEM SHALL declare each financial report's period, source, and generation time, and SHALL make it reproducible from the ledger.
13. WHEN a user performs a bulk financial export THE SYSTEM SHALL require recent re-authentication and SHALL audit the export.
14. WHEN an application release is rolled back THE SYSTEM SHALL NOT delete journal or audit history.

## Negative criteria

- No unbalanced journal batch accepted.
- No edit or delete of a posted entry.
- No financial total derived only from order status.
- No automated payout while `G-PAYOUT-01` is closed.
- No reconciliation exception auto-resolved without an authorized decision.
- No money value stored as a float.
