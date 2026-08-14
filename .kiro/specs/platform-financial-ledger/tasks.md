# Tasks — Platform Financial Ledger and Settlement

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Define `journal_batches` / `journal_entries` with integer minor-unit amounts. _Requirements: 1, 11_
- [x] Enforce debit=credit at the **database** level so no application path can bypass it. _Requirements: 1_
- [ ] Enforce append-only: no update or delete grant on journal tables for the app role. _Requirements: 2_
- [x] Implement unique `business_key` idempotency and document the key format per source. _Requirements: 5_
- [x] Write journal batches in the same transaction as the causing state change. _Requirements: 3_
- [x] Bind every batch to an explicit `badan_usaha` / merchant entity. _Requirements: 4_
- [x] Implement reversal-plus-correction flow; never edit a posted batch. _Requirements: 2_
- [x] Implement vendor payable eligibility as a rule separate from paid state. _Requirements: 8_
- [x] Implement manual payout with amount, proof, approver, and reference while `G-PAYOUT-01` is closed. _Requirements: 9_
- [x] Implement reconciliation against provider statements producing exceptions, not adjustments. _Requirements: 10_
- [x] Require authorized decision plus audit to resolve each exception. _Requirements: 10_
- [x] Require recent re-authentication for bulk financial export. _Requirements: 13_
- [x] Add tests: unbalanced batch rejected; duplicate business key posts once. _Requirements: 1, 5_
- [ ] Add tests: partial refund allocation; chargeback; payable hold and release. _Requirements: 7, 8_
- [x] Add tests: reports reconcile to journal, not to order status. _Requirements: 6, 12_
- [ ] Add tests: release rollback preserves journal and audit history. _Requirements: 14_

Checked boxes are evidenced row by row in **Implementation status** below, each against a file
in this branch. The three unchecked boxes are unchecked deliberately, not by oversight — the
reasons are in that section (`AC2` role-level revoke, `AC7` refund/chargeback, `AC14` rollback
test). **A tick means the work landed, not that the acceptance criterion is closed for merge** —
the debit=credit box is enforced by a PostgreSQL trigger and is unenforced on SQLite, the
manual-payout box ships
with its proof verifier deliberately unbound, the re-authentication box depends on a step-up
flow another lane owns, and the reports box could not be tested against an order table because
none exists. Each caveat is stated in full in the matching row below.

## Implementation status

Recorded 11 Aug 2026 on branch `lane/l4-financial-ledger` at `713442f`, after Tasks 1–7 of
`docs/superpowers/plans/2026-08-09-platform-financial-ledger.md`. This section is the
**account-by-account reconciliation** the NOT TESTED note below previously said had never been
done. Every row cites the file that makes its claim true; a claim that could not be verified in
this tree says `NOT VERIFIED` rather than being softened. Statuses here describe *this branch* —
where a defect is fixed on another lane's branch, the row says so and does not report it closed.

`docs/domain/financial-model.md` §6 "Ledger contract" is the spine: its seven minimum rules and
these ACs are the same contract stated twice. §6 now carries the same reconciliation from the
domain side.

| AC | Status | Where the guarantee actually lives |
|---|---|---|
| 1 | **Satisfied — database-enforced** | `assert_balanced_batch()` constraint trigger, `database/migrations/2026_08_09_110100_create_journal_entries_table.php:48-81`. The application deliberately does **not** own this: `app/Platform/FinancialLedger/Contracts/Journal.php:37-40` states an implementation "must not present itself as the balance gate". Evidence: `tests/Feature/FinancialLedger/JournalPostTest.php:303`, `.../JournalSchemaTest.php:70`. **Caveat:** the trigger is PostgreSQL-only, so these assertions skip on SQLite; CI runs PostgreSQL 18. |
| 2 | **Satisfied in application code — NOT enforced at the role level** | Reversal is a new linked batch, never an edit: `app/Platform/FinancialLedger/Journal.php` `postReversal()`, `reverses_batch_id` FK in `database/migrations/2026_08_09_110200_add_reverses_batch_id_to_journal_batches_table.php`. Reversed-ness is derived, so no `UPDATE` exists at all (`tests/Feature/FinancialLedger/JournalReversalTest.php:202`, `.../JournalAppendOnlyTest.php:43`). **Open gap:** `sql/revoke-journal-mutations.sql` is documented as NOT executed and is blocked on finding N-1 (one PostgreSQL role per environment owns the schema and runs the app). Two tests deliberately pin the surviving bypasses so they fail loudly once the split lands: `tests/Feature/FinancialLedger/JournalAppendOnlyTest.php:130` and `:159`. |
| 3 | **Satisfied as a contract; not mechanically enforceable** | `post()` opens no transaction of its own (`tests/Feature/FinancialLedger/JournalPostTest.php:123`, `tests/Contract/LedgerSeamContractTest.php:74`), so the caller's transaction is the pairing guarantee. `app/Platform/FinancialLedger/Contracts/Journal.php:45-50` states in writing that the interface cannot enforce this. Every in-lane caller wraps: `Actions/ManualPayout.php:251`, `Actions/VendorPayable.php:134`, `Actions/RunReconciliation.php:146`, `Actions/ResolveException.php:180`. `postReversal()` is the deliberate exception and owns a transaction (`Journal.php:173`) so the batch and its `JOURNAL_REVERSAL` audit row are atomic. |
| 4 | **Satisfied — database-enforced, with a scope limit** | `journal_batches.entity_ref` is NOT NULL and indexed, `database/migrations/2026_08_09_110000_create_journal_batches_table.php:17,24`; `tests/Feature/FinancialLedger/JournalSchemaTest.php:99`. **Scope limit:** `entity_ref` is a string with no foreign key, because no `badan_usaha` table exists in this repository. Read authorization resolves permitted entities from `scope_assignments` instead (`app/Platform/FinancialLedger/FinanceLedgerReadAuthorizer.php`). That every posted `entity_ref` names a real registered entity is `NOT VERIFIED`. |
| 5 | **Satisfied — database-enforced** | `journal_batches.business_key` UNIQUE plus a source-prefix CHECK, `database/migrations/2026_08_09_110000_create_journal_batches_table.php:16,43-44`. Key formats per source are documented once, on the interface: `app/Platform/FinancialLedger/Contracts/Journal.php:57-60`. Evidence: `tests/Feature/FinancialLedger/JournalPostTest.php:152`, `.../ManualPayoutTest.php:423`, `tests/Contract/LedgerSeamContractTest.php:63`. |
| 6 | **Satisfied for every mutable financial table that exists — the literal order case is NOT VERIFIED** | `app/Platform/FinancialLedger/LedgerReport.php` reads `journal_batches`/`journal_entries` only, pinned by a divergence plant in `tests/Feature/FinancialLedger/LedgerReportTest.php:68`. **Caveat:** this branch has no `orders` table (only `booking_drafts`), so the plant diverges a `vendor_payables` row instead. The AC's own wording — "never to mutable order state" — cannot be tested literally until `booking-and-order-orchestration` ships an order table. |
| 7 | **Partial — two of four operation types** | Vendor payable (`Actions/VendorPayable.php`) and payout (`Actions/ManualPayout.php`) are distinct types with their own authorization, journal shape, and audit. **Refund** exists only as a journal shape, `app/Platform/FinancialLedger/JournalReversalKind.php:29` — there is no refund object, no line allocation, no refund authorization or audit action, so `financial-model.md` §10 is unmet. **Chargeback** is a reserved member of the `source_type` closed list (`database/migrations/2026_08_09_110000_create_journal_batches_table.php:35-36`) and nothing else. Both halves are owned by `platform-payment-adapter` (L3), which adds `PAYMENT_REFUND`/`PAYMENT_CHARGEBACK` to `SensitiveActions::ACTIONS` in its own plan. |
| 8 | **Satisfied — database-enforced** | `app/Platform/FinancialLedger/VendorPayableEligibility.php` decides eligibility from fulfilment evidence and dispute window, separately from paid state; `VendorPayableState.php` keeps held/payable/paid as three states. CHECK constraints couple `state` to `eligible_at`/`paid_at` in `database/migrations/2026_08_09_120000_create_vendor_payables_table.php` and `2026_08_10_120300_enforce_vendor_payable_payout_consistency.php`. Evidence: `tests/Feature/FinancialLedger/VendorPayableAssessmentTest.php:42-212`, and for the second half of the AC — a payable state does not imply paid out — `.../VendorPayableAssessmentTest.php:164` and `.../ManualPayoutTest.php:659`. |
| 9 | **Satisfied, with two named residual gaps** | `Actions/ManualPayout.php` records amount, proof, approver, and reference (`tests/Feature/FinancialLedger/ManualPayoutTest.php:93`); `tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php` is a structural detector for an automated transfer path, and the `payouts` schema has no column one could record itself in (`:285`). `G-PAYOUT-01` is seeded **closed** (`database/migrations/2026_07_26_120400_seed_feature_gate_registry.php:86,103`). **Residual:** the detector does not catch a call shaped `$this->bank->createTransfer(...)` (deferred as T4-M4, disclosed in the test's own doc block); and `PayoutProofVerifier` is deliberately left unbound and rejecting until L1's DocumentVault adapter is wired at merge (`tests/Feature/FinancialLedger/FinancialLedgerBindingsTest.php:105`). |
| 10 | **Satisfied for the exception categories modelled — partial against `financial-model.md` §12** | `Actions/RunReconciliation.php` produces findings and never adjusts the ledger; `Actions/ResolveException.php` requires a `finance` role from server-side `ActorContext`, a mandatory reason, and writes the `RECONCILIATION_EXCEPTION_RESOLVED` sensitive audit event. Period closure explicitly does not resolve (`tests/Feature/FinancialLedger/ResolveReconciliationExceptionTest.php:559`). **Partial:** `app/Platform/FinancialLedger/ReconciliationExceptionType.php` models five categories; §12 names ten. Merchant mismatch, fee mismatch, late success, duplicate provider reference, refund mismatch, and payout failure are absent, and most depend on L3 provider data that does not exist yet. |
| 11 | **Satisfied inside this module — violated at the booking seam on this branch** | `journal_entries.amount_minor` is an unsigned big integer and `currency` is CHECK-constrained to `IDR`: `database/migrations/2026_08_09_110100_create_journal_entries_table.php:19,44-45`. `app/Platform/FinancialLedger/Money.php` is the only conversion point; a grep for `(float)`, `float $`, `: float` and `double(` over `app/Platform/FinancialLedger/` returns nothing. **Open on this branch:** `app/Domain/Booking/BookingDraftQuery.php:86` still casts a price version to `float` — that is retrofit finding **F15**, fixed on `lane/l3-payment-adapter` and not here. See `docs/planning/retrofit-backlog.md` F15. |
| 12 | **Satisfied** | `app/Platform/FinancialLedger/LedgerReportResult.php:32-38` declares kind, period, entity scope, source, and generation time as constructor-promoted properties. Reproducibility rests on deterministic ordering: `tests/Feature/FinancialLedger/LedgerReportTest.php:30,111`. |
| 13 | **Satisfied in this module's own gate — the platform step-up chain is wired and tested as of PR #21** | `Actions/BulkFinancialExport.php` checks a reason-scoped `reauthentication_events` row for `bulk_financial_export` and writes a per-export `BULK_FINANCIAL_EXPORT` audit row; refusals are covered at `tests/Feature/FinancialLedger/BulkFinancialExportTest.php:340,367,386,507`. The route carries `RequireRecentAuthentication`, `EnforceMfaChallenge`, and a throttle. **The wiring this lane deferred to `fix/reauthentication-satisfy-wiring` landed 11 Aug 2026 (PR #21, commit `a6acd7c`):** `MfaChallenge::submit()` now calls `ReauthenticationService::satisfy()` with the pending reason, so a completed MFA challenge mints the matching `satisfied` `bulk_financial_export` row and `EnforceMfaChallenge` no longer self-redirects (its `CHALLENGE_ROUTE_NAME` exemption). `MfaChallengeSatisfiesRecentAuthenticationTest` proves the reason-specific minting and single-use semantics. What remains NOT TESTED is the end-to-end HTTP export-after-MFA-challenge path as a single test; the two halves are each covered. |
| 14 | **NOT VERIFIED** | The append-only half is well covered (AC2 row), but no test in this repository performs or simulates a release rollback, and the journal migrations' `down()` methods are destructive by design. "A rollback does not delete journal or audit history" is therefore asserted by nothing. This is why the last task box stays unchecked. |

### Chart of accounts and the finance-owner open item

`app/Platform/FinancialLedger/ChartOfAccounts.php` is the canonical minimal initial chart of
accounts and is seeded by `database/migrations/2026_08_09_100000_create_coa_accounts_table.php`
(plus the additive `2026_08_10_120000_add_vendor_liability_account.php`). The codes are not
restated here — `AGENTS.md` §Documentation forbids duplicating canonical data in a second
hand-maintained document.

**Still open, and this is the load-bearing one: no finance owner has approved that chart.** The
file's own doc block calls the rows seed data rather than a closed enum precisely so finance can
add codes later, but "not yet approved" is not the same as "provisional by design". Likewise
`FIN-DEC-01` … `FIN-DEC-07` are `TBD` and `FIN-DEC-08` is `GATED` in
`docs/domain/financial-ledger-and-settlement.md`, and `docs/testing/release-gates.md:75` is
unchecked. Nothing in Tasks 1–7 opened any of those gates.

## Design system

Financial UI lives in `admin-operations` (ADM-070, ADM-090) and the vendor portal, but the **presentation contract** is owned here. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- All money uses `text-right tabular-nums` and `--font-mono`; totals `--font-weight-bold`. Never render a float.
- Paid, payable, and paid-out are **three separate indicators** via `<x-mk.badge>` §3.6 — never merged into one "settled" badge.
- Reconciliation exceptions use `intent=danger` when they affect a customer balance and `intent=pending` when they are awaiting a decision; an exception is **never** rendered as resolved because a period closed.
- Reversal and payout confirmations use `<x-mk.modal>` §3.4: consequence stated, reason captured, destructive option not default-focused, recent re-authentication required.
- Bulk export renders as `variant=secondary`, never `primary`, and never adjacent to a benign action (§3.5).
- Required states per §6: loading · empty ("Belum ada transaksi pada periode ini") · validation error · authorization failure (finance-scoped, no existence leak) · provider unavailable (statement fetch failed → say so, never show a partial period as complete) · duplicate-safe · pending · success (quiet) · support · responsive.

## NOT TESTED

Rewritten 11 Aug 2026. The previous text — "Nothing here is implemented … have **not** been
reconciled … no chart of accounts has been defined" — was accurate when written and is now
stale on all three counts. Tasks 1–7 of
`docs/superpowers/plans/2026-08-09-platform-financial-ledger.md` landed on branch
`lane/l4-financial-ledger`, the reconciliation is in **Implementation status** above, and
`app/Platform/FinancialLedger/ChartOfAccounts.php` defines a minimal initial chart. The
paragraph is replaced rather than amended because every clause in it changed.

**Still gate-blocked, unchanged by any of that work.** `FIN-DEC-01` … `FIN-DEC-07` are `TBD`
and `FIN-DEC-08` is `GATED` in `docs/domain/financial-ledger-and-settlement.md`;
`docs/testing/release-gates.md:75` ("FIN-DEC decisions required by the activated money path are
approved") is unchecked; `docs/planning/sprint-plan.md` still lists this spec as gated on those
approvals. **Correction to the old note:** the `G-PAY-01` / `G-PAYOUT-01` states are no longer
"unknown" — `database/migrations/2026_07_26_120400_seed_feature_gate_registry.php:85-86,103`
seeds every gate `closed`, including both of these, and their flags
(`feature.online_payment`, `feature.vendor_auto_payout`) default to `false` at `:125,133`.
Closed is what the shipped code assumes, so this is a stronger statement than "unknown", not a
relaxation.

**Verified but not closed — carry into the merge sign-off.** These are AC-level facts a human
approving financial code needs, and this spec is their only tracked home; the lane's execution
ledger under `.superpowers/` is git-ignored and does not survive the merge, so the PR body must
restate them:

- **AC2, role-level append-only is not in force.** `sql/revoke-journal-mutations.sql` is
  documented as NOT executed and is blocked on finding N-1. Two tests pin the surviving
  bypasses so they fail loudly once the role split exists.
- **One reversal per batch, ever, is a database-level financial policy.** The UNIQUE index on
  `journal_batches.reverses_batch_id` makes it expensive to revise later. It needs an explicit
  human decision, not an inherited default.
- **`payouts.payable_id` is UNIQUE** — one payable is discharged exactly once, same class of
  permanent policy.
- **A `vendor_payables` row cannot be deleted.** The deferred consistency trigger in
  `database/migrations/2026_08_10_120300_enforce_vendor_payable_payout_consistency.php:106-107`
  calls the pair assertion with `OLD.id` on every non-INSERT, and that function raises
  `ERRCODE = '23503'` when the row is not found (`:60-63`) — so any DELETE fails with a
  misleading foreign-key-shaped error. Latent today (nothing deletes
  these rows) and **not** superseded by the journal revoke, which names only the journal tables.
- **The deferred triggers' positive path is not tested.** `RefreshDatabase` rolls back before
  `COMMIT`, so a `DEFERRABLE INITIALLY DEFERRED` trigger never fires on the happy path. Only
  rejection cases are covered.
- **The module's identity seam was verified against a real identity adapter from lane L5 (12 Aug 2026).** The 11 Aug 2026 note on this lane ("`ActorContext::$roles` is always empty today… every financial surface fails closed for every real actor") was accurate for this lane's state and is now stale: L5 populated `ActorContext::$roles`/`$scopes` for real (`actor_role_assignments`, `scope_assignments`), so `Actions/ResolveException.php`'s `finance`-role requirement and `AuthorizeOrderPaymentOpening`'s role+scope checks run against real actor state, not only bound fakes. The L4-era fail-closed posture for *unpopulated* roles is superseded; the human financial sign-off still reviews the tests as shipped.
- **`PayoutProofVerifier` is unbound by design** and rejects every proof reference until L1's
  DocumentVault adapter is wired at merge integration.
- **`LedgerReport::summary($period, null)` remains a live unscoped-read shape**, reachable by no
  current caller. It is the residual form of the cross-entity exposure defect that an
  independent review found and closed in this lane.
- **Two `SensitiveActions::ACTIONS` additions landed outside the sequence the plan anticipated**
  — `JOURNAL_REVERSAL` and `RECONCILIATION_EXCEPTION_RESOLVED`. Both were approved; both must be
  named in the PR body, and `lane/l3-payment-adapter` appends to the same array, so whichever
  lane merges second must keep both sides rather than take either wholesale.
- **The bulk export is now step-up protected end to end.** The 11 Aug 2026 caveat — "not usable end to end, in either direction, until `fix/reauthentication-satisfy-wiring` lands: nothing mints a satisfied `bulk_financial_export` event, and `EnforceMfaChallenge` currently routes an enrolled admin into a known self-redirect" — was resolved by PR #21 (11 Aug 2026, commit `a6acd7c`): a completed MFA challenge mints the satisfied reason-scoped event (`MfaChallenge::submit()` → `ReauthenticationService::satisfy()`), and the `EnforceMfaChallenge` self-redirect exemption exists. The export route (`routes/web.php`) carries `RequireRecentAuthentication::bulk_financial_export` with the MFA-challenge page as its challenge target, so a stale enrolled admin completes the challenge and is redirected back with the satisfied row in place. Remaining gap: the two halves (challenge-satisfies-reauth; export-checks-reauth) are tested separately — no single test drives the full HTTP export-after-challenge flow.

**Not verified anywhere.** AC14 (release rollback preserves history) has no test; AC6's literal
order-status case cannot be tested until an order table exists (orders shipped 13 Aug 2026 with
lane L6, so this is now testable — but no ledger test has been written against it yet); AC7's
refund and chargeback **recording** halves shipped with `platform-payment-adapter` Task 6
(`Actions/RecordRefund`/`RecordChargeback` on `payment_reversals`, 11 Aug 2026) while their
journal-referencing half remains unbuilt (no `Journal::postReversal()` call site); and no
finance owner has approved the chart of accounts.

**No traceability-matrix row covers this module.** `docs/domain/traceability-matrix.md` §B is
scoped to RKS-derived stakeholder-workflow expectations with screen IDs and E2E test families,
and carries no row for any platform foundation — audit, outbox, feature-gate, document-vault,
notifications, or this one. The `_Requirements: N_` references in the task list above are this
spec's traceability, per Kiro's own convention; adding foundation rows to a workflow-scoped
matrix would create a second, rival mapping rather than fill a gap. Recorded as a known
coverage boundary of that document, not silently ignored.
