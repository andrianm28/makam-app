# Platform Financial Ledger and Settlement — Implementation Plan (Lane L4, Wave 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `platform-financial-ledger` (`.kiro/specs/platform-financial-ledger/`) as a real `app/Platform/FinancialLedger/**` module owning the money record: DB-enforced balanced journal batches in integer minor units, append-only history, same-transaction writes as the causing state change, `badan_usaha` binding, business-key idempotency, reversal-plus-correction, vendor payable/payout separation (manual while `G-PAYOUT-01` closed), reconciliation-to-exceptions, reproducible reports, bulk-export re-authentication, and release-rollback safety — and execute the Wave 0 rulings for F15/S-Q3 (integer minor units; add `PRICE_VERSION_RECORDED` to `SensitiveActions`).

**Architecture:** The ledger is the money record. `PaymentAdapter` (L3) reports what a provider did; this module records what it means financially. Order workflows read projections, never raw ledger rows (AC6). Balance is enforced at the **database** level (constraint/trigger) so no application path can bypass it (AC1). The ledger is **append-only**: corrections post a new reversing batch referencing the original, never an edit (AC2). Money is integer minor units — no float anywhere (AC11, Wave 0c). Payout is manual while `G-PAYOUT-01` is closed (AC9). Reconciliation compares against provider statements and produces exceptions requiring an authorized decision (AC10).

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL 18, Pest/PHPUnit, Redis queue via Horizon (`reports` queue for reconciliation; `critical` for journal-triggered propagation per queue-and-outbox §2).

---

## Current state — read this before planning any change

### What is already built

- `app/Platform/FinancialLedger/.gitkeep` only — the module does not exist.
- No journal/payable/payout/reconciliation tables exist anywhere (verified: no migration names `journal*`/`payout*`/`reconciliation*`).
- The discipline this module extends is established and tested: `Audit::record()/wrap()` (the one `audit_events` write API), `Outbox::record()` (the one `outbox_events` write API), `OutboxClassification`/`SensitiveActions` closed lists, `ScopeAssignment`/`ActorContext` for entity/actor scoping, `ReauthenticationService` for AC13's recent re-authentication (prepared, no real controller yet).
- `SensitiveActions::ACTIONS` already includes `VENDOR_PAYOUT` (mandatory reason) — AC9's manual-payout audit guard is pre-wired.
- Wave 0 ruling 0c (approved): money stored/carried as integer minor units (IDR × 100) everywhere; F15 (`(float) $priceVersion->amount` at the Booking seam) resolved by L4/L3; `PRICE_VERSION_RECORDED` ADDED to `SensitiveActions::ACTIONS` by L4's plan. Verified: no money is stored in any deployed DB today, so the contract is additive and conversion-free — **no destructive migration**.
- ADR-0020 (cited by the spec) and `docs/domain/financial-ledger-and-settlement.md` + `docs/domain/financial-model.md` exist as design context; the spec's NOT TESTED note says they've "not been reconciled against these criteria account by account, and no chart of accounts has been defined — that is a prerequisite and an open question for the finance owner."
- `event-catalog.md` names consumers the ledger feeds: `payment.received.v1` (PaymentAdapter producer), `order.status_changed.v1`, `grave.reminder_sent.v1`. The ledger does NOT invent new events in this lane beyond what it must emit; verify against `event-catalog.md`, don't assume.

### Status / NOT TESTED

`platform-financial-ledger/tasks.md:33-35` is the authority: "Nothing here is implemented. The **FIN-DEC approvals required by `release-gates.md` §H are not granted**, and `G-PAY-01` / `G-PAYOUT-01` states are unknown, so this spec is gate-blocked."

This lane builds the ledger platform **without** the FIN-DEC approvals being granted for production money movement: the ledger records real internal transactions (paid effects, refunds, payables) in the shared money path as the platform requires — that is platform behavior, not production merchant activation. `G-PAYOUT-01` stays closed: payout is manual only (recorded amount, proof, approver, reference; no automated transfer). Production activation of any real-money path remains gated on FIN-DEC + human sign-off, unchanged.

### What the spec requires (AC → design mapping)

| AC | Requirement (abridged) | Design surface |
|---|---|---|
| 1 | Balanced journal batch, DR = CR enforced at write time, DB level | `journal_batches` + `journal_entries`; Postgres constraint/trigger rejects imbalance at INSERT time |
| 2 | Append-only; corrections = new reversing batch referencing original | `Journal::postReversal()`; no UPDATE/DELETE grant for journal tables |
| 3 | Same DB transaction as the causing state change | `Journal::post()` called inside the caller's transaction (the `Audit::wrap`/`Outbox` "open no own transaction" discipline, extended) |
| 4 | Bind every batch to explicit `badan_usaha`/merchant entity | `journal_batches.entity_ref` (badan_usaha) required, non-null, validated |
| 5 | Idempotent journal writes by business key | `journal_batches.business_key` UNIQUE; a retried webhook/job collides and posts nothing |
| 6 | Order status is not the financial source of truth | Reports/projections read journal references only; no financial total derived from order status (test-enforced) |
| 7 | Refund/chargeback/vendor-payable/payout as distinct operation types, each with own auth, journal shape, audit | `ReversalService` (L3), `VendorPayable`, `ManualPayout` Actions, per-type audit actions |
| 8 | Payable eligibility explicit; paid ≠ payable ≠ paid-out | `vendor_payables` eligibility rule separate from paid state; three states never merged |
| 9 | While `G-PAYOUT-01` closed: manual payout only (amount, proof, approver, reference) | `ManualPayout` Action + `SensitiveActions::VENDOR_PAYOUT` mandatory reason |
| 10 | Reconciliation → exceptions, never silent adjustment; authorized decision per exception | `RunReconciliation` + `reconciliation_exceptions`; `ResolveException` Action with authorization + audit |
| 11 | Explicit currency/rounding; never float | integer minor units everywhere; `config/money.php` (co-owned with L3) |
| 12 | Reports declare period, source, generation time; reproducible from ledger | `LedgerReport` query with metadata; report source = journal reference only |
| 13 | Bulk financial export requires recent re-authentication + audit | `RequireRecentAuthentication` + `ReauthenticationService`; `Audit::record(BULK_FINANCIAL_EXPORT)` |
| 14 | Release rollback never deletes journal/audit history | no destructive migration; rollback is forward-compatible (reversal pattern) |

## NOT TESTED (this lane)

- Production money movement / merchant activation (FIN-DEC §H not granted; `G-PAY-01`/`G-PAYOUT-01` closed for production).
- Automated payout (forbidden while `G-PAYOUT-01` closed; manual path is the tested path).
- Live provider-statement reconciliation (no provider statement source wired — `RunReconciliation` accepts a statement record/fixture; a real statement adapter is a later HUMAN/provider decision).
- Chart of accounts: the finance owner must define the final COA. This lane ships a **minimal, explicit initial COA** (documented in the module) so the ledger is functional and auditable; the COA is data (a `coa` table), so replacing it is non-destructive and does not block the platform.

## Global Constraints

- Balance enforced at the DB (AC1): a batch is inserted with its entries in one statement group; a Postgres constraint trigger rejects the batch unless `SUM(DR) = SUM(CR)`. Application-level checks are a convenience, never the authority.
- Append-only (AC2): `journal_batches`/`journal_entries` get NO UPDATE/DELETE grant for the app role (the `revoke-audit-mutations.sql` precedent). Corrections post reversing batches referencing the original. Rollback is forward-compatible (AC14).
- Same-transaction discipline (AC3): `Journal::post()` opens no transaction of its own (identical to `Audit::record()`/`Outbox::record()`); callers post from inside their own `DB::transaction()`. No journal write ever commits separately from the state change that caused it.
- Idempotency (AC5): `business_key` is UNIQUE; format is source-prefixed and documented per source (`payment:{provider_event_id}`, `renewal:{grave_record_id}:{period}`, `refund:{original_batch_key}`, `manual_verify:{verification_id}`). At-least-once delivery is safe because the colliding key posts nothing.
- Money is integer minor units (AC11, Wave 0c): no float, no decimal-string arithmetic in application code. `Money` value object (co-owned with L3) is the only money type crossing module boundaries.
- Paid / payable / paid-out are three separate states, never merged into one "settled" badge (AC8, `AGENTS.md` "Paid does not mean completed"; design-system §3.6).
- Manual payout while `G-PAYOUT-01` closed (AC9): no automated transfer code path exists at all (structural, not just gated); payout records amount, proof, approver, reference, and is audited with `VENDOR_PAYOUT` mandatory reason.
- Reconciliation never adjusts the ledger to make a statement match (AC10); every difference becomes an exception requiring an authorized decision + audit. "Provider unavailable" renders as an honest statement-fetch failure, never a partial period shown complete (tasks.md §Design system).
- Restricted data never enters a journal payload, audit metadata, or log (`AGENTS.md` §Observability); journal entries carry account codes + minor-unit amounts + references, never customer PII.
- No new outbox event names invented in this lane; consumers use existing catalog names. Verify each event this lane emits exists in `event-catalog.md`, add a catalog entry only with a documented producer consumer.
- Capacity: worktree + staggered CI per Wave 0 S4-T9 baseline. Reconciliation runs on the `reports` queue (never starves `critical`/`urgent`/`notifications`).
- `PRICE_VERSION_RECORDED` joins `SensitiveActions::ACTIONS` in this lane (Wave 0c ruling — human-approved), and L3 adds `PAYMENT_REFUND`/`PAYMENT_CHARGEBACK`; the two additions are cross-lane coordinated so both `SensitiveActions` edits land once.

## File Structure

New files under `app/Platform/FinancialLedger/`:

| File | Responsibility |
|---|---|
| `Contracts/Journal.php` | the `post()`/`postReversal()` seam co-owned with L3 (single source of truth; L3 consumes, L4 implements) |
| `Money.php` | value object: integer minor units, `fromDecimal`, `add`, `subtract`, `compare`, `format` (co-owned with L3) |
| `Journal.php` | the ONE write API for `journal_batches`/`journal_entries` — `post()` (balanced, same-transaction, business-key unique) and `postReversal()` (references original) |
| `ChartOfAccounts.php` | minimal initial COA (data-backed seed, documented; finance owner may extend) |
| `Actions/PostJournalBatch.php` | internal: inserts batch + entries, relies on DB balance trigger, throws on duplicate business key |
| `Actions/VendorPayable.php` | AC8: eligibility rule (fulfilment evidence, dispute window) separate from paid state |
| `Actions/ManualPayout.php` | AC9: manual payout with amount, proof, approver, reference; `VENDOR_PAYOUT` sensitive audit |
| `Actions/RunReconciliation.php` | AC10: compare journal vs provider statement record; produce exceptions, never adjustments |
| `Actions/ResolveException.php` | AC10: authorized decision + audit per exception |
| `Actions/BulkFinancialExport.php` | AC13: recent re-auth + audit on export |
| `Models/JournalBatch.php`, `Models/JournalEntry.php`, `Models/VendorPayable.php`, `Models/Payout.php`, `Models/Reconciliation.php`, `Models/ReconciliationException.php`, `Models/Account.php` | Eloquent models, `$guarded = ['*']`, immutable journal rows |
| `sql/revoke-journal-mutations.sql` | append-only grants for journal tables |
| `ReconcileStatementJob.php` | scheduled reconciliation on the `reports` queue |
| `FinancialLedgerServiceProvider.php` | binds `Contracts\Journal` → `Journal` |
| `routes/` Filament admin resource (ADM-070/ADM-090 consumed by `admin-operations`; this lane provides the data/actions, the screens are that spec's) |

Migrations (all additive, `2026_08_09_*`): `create_coa_accounts_table` (seed), `create_journal_batches_table`, `create_journal_entries_table` (+ constraint trigger `assert_balanced_batch`), `create_vendor_payables_table`, `create_payouts_table`, `create_reconciliations_table`, `create_reconciliation_exceptions_table`. DB triggers/constraints: balance assertion, closed-list on `direction`/`status`, UNIQUE `business_key`.

---

## Task 1: Money value object + minimal COA (AC11, Wave 0c)

**Files:** `Money.php`, `ChartOfAccounts.php`, migration `create_coa_accounts_table`

- `Money`: integer minor-unit wrapper with `fromDecimal(string)`, `add`, `subtract`, `negate`, `compare`, `isPositive`, `format`. No float property, no float constructor path (reject float input with a type error). Co-owned: L3 uses the same class via the shared seam; the class lives in the FinancialLedger namespace, L3 imports it.
- Minimal initial COA (seeded, data-backed, documented): `1000 Aset — Piutang Pelanggan (DR)`, `2000 Liabilitas — Pendapatan Diterima (CR)`, `4000 Pendapatan — Layanan (CR)`, `5000 HPP / Komisi Vendor (DR)`, `6000 Beban — Biaya Channel (DR)`, `6100 Beban — Refund (DR)`, `7000 Rekening Kas/Bank (DR)`. Each account: code, name, `normal_balance` (DR|CR). The finance owner may add accounts non-destructively.
- `PRICE_VERSION_RECORDED` added to `SensitiveActions::ACTIONS` (Wave 0c ruling) — a financial change, human-approved in Wave 0, executed here. (Cross-lane note: L3 adds `PAYMENT_REFUND`/`PAYMENT_CHARGEBACK` in its own Task 6; both edits land once.)

- [ ] **Step 1:** `Money` value object + tests (no float path, fromDecimal lossless, arithmetic).
- [ ] **Step 2:** COA table + seed + `ChartOfAccounts`.
- [ ] **Step 3:** `SensitiveActions::ACTIONS` += `PRICE_VERSION_RECORDED`.
- [ ] **Step 4:** Tests: float input rejected; COA seeded; `PRICE_VERSION_RECORDED` requires reason.

---

## Task 2: Journal schema + DB balance enforcement (AC1, AC4)

**Files:** migrations `create_journal_batches_table`, `create_journal_entries_table` + trigger; `Models/JournalBatch.php`, `Models/JournalEntry.php`

- `journal_batches`: `id` (uuid pk), `business_key` (UNIQUE, source-prefixed), `entity_ref` (`badan_usaha_ref` non-null — AC4), `source_type` (payment|manual_verification|renewal|refund|chargeback|payout|reversal), `source_id`, `correlation_id`, `occurred_at`, `status` (open|posted|reversed — closed list, DB CHECK). No `updated_at` semantics for posted batches.
- `journal_entries`: `batch_id` FK, `account_code` FK → `coa_accounts`, `direction` (DR|CR, DB CHECK), `amount_minor` (bigint, non-negative; sign lives in direction), `currency` (fixed 'IDR'), `reference` (nullable free-text reference, never PII). Partial index on `(batch_id, direction)`.
- **Balance trigger** `assert_balanced_batch` (Postgres constraint trigger): on INSERT into `journal_entries`, after insert, verify the batch's `SUM(CASE direction='DR' THEN amount_minor ELSE -amount_minor END) = 0`; violate → abort with a clear error. This is the DB-level authority (AC1 "not by a later report"). Must be Postgres-only safe (SQLite CI skip, same precedent as Outbox's `FOR UPDATE SKIP LOCKED`).
- Append-only SQL revoke applied in Task 6.

- [ ] **Step 1:** Write both migrations + the balance trigger (verify the trigger fires on entry insert, not a later app check).
- [ ] **Step 2:** Models with `$guarded = ['*']`; `JournalBatch` exposes `isBalanced()`, `total()` (minor units).
- [ ] **Step 3:** Tests: unbalanced batch rejected at the DB; balanced batch accepted; `business_key` collision rejected; `entity_ref` required; closed-list violations rejected; trigger works under concurrent inserts (batch-level, not row-level — two entries inserted in one statement group).

---

## Task 3: `Journal` write API + reversal (AC2, AC3, AC5)

**Files:** `Contracts/Journal.php`, `Journal.php`, `Actions/PostJournalBatch.php`

- `Journal::post(string $businessKey, int|string $entityRef, string $sourceType, int|string $sourceId, array $entries /* [{account, direction, amountMinor, reference?}] */, ?string $correlationId, ?string $occurredAt): JournalBatch`
  - Opens NO transaction (AC3 — caller's transaction). Insert batch (status `posted`) + entries in one statement group; DB balance trigger enforces AC1. On `business_key` collision, the INSERT throws → caller's transaction rolls back → nothing double-posts (AC5). `correlation_id` threads the trace across the outbox/queue/provider flows (`AGENTS.md` §Observability).
  - Validates each entry account exists (COA FK), direction is DR|CR, `amountMinor` is a non-negative int.
- `Journal::postReversal(string $originalBusinessKey, string $reason, ...)`: posts a new batch that reverses every original entry (direction flipped, amounts equal), `status = reversed` on the original (a forward-only status marker, NOT an edit — original entries untouched), references the original `business_key` as `reference`, new `business_key = refund:{original}` / `reversal:{original}`. Never edits or deletes the original rows (AC2, AC14).
- A `reversed` status is a projection marker only; total/balances always read original + reversal entries.

**Correction, 10 Aug 2026 (Wave 1b rulings, user-approved):** the two bullets above are
append-corrected, not rewritten. As written they contradicted this plan's own Task 6 and
Global Constraints:

1. **No `status = reversed` UPDATE. Reversed-ness is derived, never stored.** The bullet above
   told Task 3 to write `status = 'reversed'` on the original batch; Task 6 (§"Append-only
   enforcement", this file) and Global Constraint 2 revoke UPDATE on `journal_batches` from the
   app role entirely. Whatever Task 3 wrote, Task 6 would have broken. Calling the write "a
   forward-only status marker, NOT an edit" does not change what the database sees — it is an
   UPDATE on a table this plan declares append-only, and append-only is AC2/AC14 itself.
   **Ruling: a batch is reversed if and only if a reversing batch referencing it exists.**
   Compute it by lookup; write no status column. This makes AC2/AC14 literally true and lets
   Task 6's blanket revoke apply exactly as written, with no column-level grant carve-out. It is
   also the reading this plan's own next bullet already implies ("a projection marker only").
2. **Add a `reverses_batch_id` FK.** The bullet above specified the reversal→original link as
   the original `business_key` written into `reference` — i.e. `journal_entries.reference`, which
   Task 2 built as a nullable, un-indexed, unconstrained `text` note column explicitly documented
   as never holding PII. That link *is* the audit trail for AC2 and AC14, and ruling 1 above
   depends on it being trustworthy, so it must be a real foreign key rather than a free-text note.
   **Ruling: `journal_batches` gains a nullable, indexed, self-referencing `reverses_batch_id` FK.**
   `journal_entries.reference` stays the human note column it was designed to be.

   This adds a migration to Task 3, which the File Structure section above does not list. The
   migration is additive and non-destructive: per this plan's own Current-state finding, no money
   is stored in any deployed database today, so the table holds zero production rows.

- [ ] **Step 1:** Implement `Contracts\Journal` + `Journal` implementation.
- [ ] **Step 2:** Implement `postReversal`.
- [ ] **Step 3:** Tests: post inside a caller transaction rolls back together with the state change (rollback test); duplicate business key posts nothing (caller's change also rolled back); reversal flips all entries and references the original; original rows byte-identical after reversal (immutability test); same-transaction proof mirrors `OutboxTransactionTest`/`AuditWrapTransactionTest`.

---

## Task 4: Vendor payable + manual payout (AC7, AC8, AC9)

**Files:** `Actions/VendorPayable.php`, `Actions/ManualPayout.php`, migrations `create_vendor_payables_table`, `create_payouts_table`; `Models/VendorPayable.php`, `Models/Payout.php`

- `VendorPayable::assess(...)`: payable eligibility is a SEPARATE rule from paid state (AC8): fulfilment evidence accepted (vendor work-completed event/evidence) + dispute window elapsed + order paid. Creates `vendor_payables` rows (`vendor_id`, `source_type`/`source_id`, `eligible_at`, `amount_minor`, `state = held|payable|paid`). A paid order with no eligible evidence stays `held` — never implied payable by being paid (test-enforced).
- `ManualPayout::pay(...)` (AC9): while `G-PAYOUT-01` closed, no automated transfer code exists (structural). Requires: approver with payout authorization, recent re-authentication, amount, proof reference (bank transfer record via document-vault kind, referenced not attached), `Audit::record('VENDOR_PAYOUT', outcome allowed, reason mandatory via SensitiveActions)`. Sets payable `state = paid`, posts `payout:{vendor}:{payable_ref}` journal batch (cash-out DR, vendor-payable CR). Never touches the customer's original journal rows.
- A payout is never "implied completed" by creation — paid-out requires the recorded proof + approver (AC8 three states).

- [ ] **Step 1:** `VendorPayable::assess` + eligibility tests (paid but no evidence → held; evidence + window → payable; payable ≠ paid-out).
- [ ] **Step 2:** `ManualPayout::pay` + re-auth + sensitive audit + journal.
- [ ] **Step 3:** Tests: no automated-transfer path exists (grep/structural test: no provider payout call in `app/Platform`); payout without authorization/reason/re-auth blocked; three states never merged; payout journal posts `payout:` business key idempotently.

---

## Task 5: Reconciliation + exceptions (AC10, AC12)

**Files:** `Actions/RunReconciliation.php`, `Actions/ResolveException.php`, migrations `create_reconciliations_table`, `create_reconciliation_exceptions_table`; `Models/Reconciliation.php`, `Models/ReconciliationException.php`, `ReconcileStatementJob.php`

- `RunReconciliation` (scheduled, `reports` queue): for a given period + `badan_usaha`, compare `journal_batches` totals against the provider statement record (a statement record/reference — real adapter is a later decision; this lane accepts a statement input/record). Differences → `reconciliation_exceptions` (`type`: amount mismatch|missing|extra|unbalanced, `period`, `journal_amount_minor`, `statement_amount_minor`, `status = open`). The ledger is NEVER adjusted to match (AC10 negative criteria). Missing statement → reconciliation `status = statement_missing`, rendered honestly, never a partial period shown complete (design-system §6).
- `ResolveException::resolve(...)`: requires an authorized decision (finance-scoped policy) + `Audit::record('RECONCILIATION_EXCEPTION_RESOLVED', reason mandatory, sensitive)`; sets `status = resolved`, records `decided_by`, `decided_at`, decision type (post correction | accept variance | escalate). No exception resolves by period closure.
- `ReconcileStatementJob` runs on `reports` (never starves critical/urgent/notifications).

- [ ] **Step 1:** `RunReconciliation` + exceptions (never adjustments).
- [ ] **Step 2:** `ResolveException` with authorization + audit.
- [ ] **Step 3:** Tests: statement mismatch → exception, journal untouched; missing statement → honest `statement_missing`; exception unresolved by period close; resolve requires finance scope + reason; double-resolve blocked.

---

## Task 6: Append-only enforcement + reports + bulk export (AC2, AC12, AC13)

**Files:** `sql/revoke-journal-mutations.sql`, `Actions/BulkFinancialExport.php`, `LedgerReport` query classes, Filament admin resource (data/actions; screens owned by `admin-operations`)

- Revoke UPDATE/DELETE on `journal_batches`, `journal_entries` (and `reconciliation_exceptions` after resolution) from the app role — the `revoke-audit-mutations.sql` precedent.
- `LedgerReport`: declares `period`, `source` (journal-reference-only, AC6/AC12), `generated_at`; reproducible from the ledger (deterministic, read-only). Financial totals read journal only — a test asserts no report derives a total from order status.
- `BulkFinancialExport`: requires `RequireRecentAuthentication` (AC13) + `Audit::record('BULK_FINANCIAL_EXPORT')`. Renders as `variant=secondary` (design-system §3.5), never adjacent to a benign action. Audits every export.

- [ ] **Step 1:** Revoke SQL + apply-in-CI check + app-role UPDATE/DELETE test.
- [ ] **Step 2:** `LedgerReport` + reproducibility metadata.
- [ ] **Step 3:** `BulkFinancialExport` with re-auth + audit.
- [ ] **Step 4:** Tests: report declares period/source/generated_at and reconciles to journal not order status; export without recent re-auth blocked; every export audited.

---

## Task 7: Ledger seam + L3 integration checkpoint

**Files:** `Contracts/Journal.php` (shared), L3's `tests/Contract/LedgerSeamContractTest.php`

- Confirm the `Contracts\Journal` interface satisfies L3's seam contract test (imbalance rejected, idempotent business key, same-transaction). Both PRs reference the shared interface file; merge order: L4 first preferred; if L3 lands first, L3 consumed the stub-verified contract and this lane replaces the binding.

- [ ] **Step 1:** Run the shared seam contract test against the real `Journal` implementation.
- [ ] **Step 2:** Record the cross-lane merge note in both plans' finish tasks.

---

## Task 8: Doc reconciliation + F15/S-Q3 dispositions (Wave 0c)

**Files:** `docs/domain/financial-model.md`, `docs/domain/financial-ledger-and-settlement.md`, `docs/planning/retrofit-backlog.md` §2 (F15/S-Q3 rows), `.kiro/specs/platform-financial-ledger/{tasks.md,traceability-matrix.md}`, `docs/planning/sprint-plan.md` (S8–9 row)

- Reconcile `financial-model.md`/`financial-ledger-and-settlement.md` against the ACs account-by-account (the tasks.md NOT TESTED note flags this as never done); record the minimal initial COA and the finance-owner open item.
- Mark F15 and S-Q3 `retrofit-backlog.md` rows with the Wave 0c resolution status (RULED — resolved by L4/L3; the plan's Task 1 already executed the S-Q3 addition; L3's Task 1 executed the F15 fix).
- Mark spec tasks closed per traceability; append the FIN-DEC/gates NOT-TESTED note to the sprint-plan S8–9 row.

- [ ] **Step 1:** Reconcile + annotate the two domain docs.
- [ ] **Step 2:** Update retrofit-backlog F15/S-Q3 rows to RESOLVED.
- [ ] **Step 3:** Update tasks.md/traceability-matrix.md + sprint-plan S8–9 row.

---

## Task 9: Review slices, fix wave, re-review

### 9a. Task-scoped review slices (dispatched concurrently)

1. **Balance/immutability slice** — AC1, AC2, AC5, AC14: DB balance trigger, append-only revoke, reversal correctness, rollback safety, no double-post under retry.
2. **Payable/payout slice** — AC7, AC8, AC9: eligibility rule, three-state separation, manual-only payout, sensitive audit, re-auth.
3. **Reconciliation/reporting slice** — AC6, AC10, AC12, AC13: exceptions not adjustments, authorized resolution, journal-truth reports, reproducible metadata, bulk-export re-auth.

### 9b. Bounded fix wave + 9c. Scoped re-review + 9d. Doc correction

Per the two-tier review convention (Critical + Important fixed in one bounded wave with regression tests; Minor ledgered unless trivial; doc overclaims corrected).

---

## Task 10: Finish the branch

- [ ] Merge to trunk `docs/design-system-and-planning` via PR against the Wave 1 review checkpoint (cross-lane note for the shared `Contracts/Journal` + `SensitiveActions` edits).
- [ ] Update `sprint-plan.md` S8–9 row — ledger platform complete with PR + CI run; FIN-DEC/`G-PAYOUT-01` still closed note appended.
- [ ] Update `docs/planning/retrofit-backlog.md` §2 for surfaced findings.
- [ ] Verify static analysis, tests on PostgreSQL 18, Blade content-survival gate in CI (staggered per Wave 0 capacity baseline).

## Verification

- [ ] `vendor/bin/pest` green on PostgreSQL 18, including `tests/Feature/FinancialLedger/`, `tests/Unit/Platform/FinancialLedger/`, and the shared ledger seam contract test.
- [ ] Unbalanced batch rejected at the DB; duplicate business key posts once; reversal leaves original rows immutable; same-transaction proof; rollback preserves journal + audit history.
- [ ] Payable/payout: paid ≠ payable ≠ paid-out proven; manual-only payout (no automated-transfer code path); sensitive audit + re-auth enforced.
- [ ] Reconciliation produces exceptions, never adjustments; exceptions require authorized decision.
- [ ] Reports reconcile to journal, not order status (test).
- [ ] No float anywhere (`grep -rn "(float)" app/Platform/FinancialLedger app/Domain/Booking` empty; `Money` end to end).
- [ ] `PRICE_VERSION_RECORDED` in `SensitiveActions` and requires a reason.
- [ ] App role cannot UPDATE/DELETE journal tables (revoke test).
- [ ] Static analysis + lint clean; Blade content-survival gate passes.

---

## Merge sign-off bundle — L4 (recorded 11 Aug 2026)

**Why this section exists.** Every item below was accumulated across Tasks 1–8 in
this lane's SDD ledger, `.superpowers/sdd/2026-08-09-platform-financial-ledger/progress.md`.
That path is **git-ignored** (`.gitignore:85`), so it does not survive the merge
and disappears entirely when the worktree is removed. This section is the
**durable copy**, not a duplicate: it moves the only record of these items into
the tracked tree. Task 10's PR body must restate it in full — a reviewer
approving financial code is entitled to see every one of these without access to
a deleted worktree.

`AGENTS.md` §Infrastructure-agent execution requires human review before
security, authorization, financial, privacy, or destructive-migration changes.
This lane is all five. Nothing here is a recommendation to merge.

### Database-level financial policy needing explicit sign-off

1. **M6 — one reversal ever, enforced by a UNIQUE index** on
   `journal_batches.reverses_batch_id`. This makes "a batch may be reversed at
   most once, permanently" a *database* policy, not a code choice someone can
   revise cheaply later. The reasoning was reviewed and judged sound, but the
   class of decision requires a human.
2. **`payouts.payable_id` UNIQUE** — one payout per payable, same character.

### Known-open defects, deliberately not fixed

3. **T4-M1 — the `vendor_payables` DELETE trigger rejects even a consistent
   delete.** `assert_vendor_payable_payout_pair(OLD.id)` raises `NOT FOUND` on
   any DELETE, and fails with a misleading FK-style message rather than a
   deliberate policy one. Latent today (nothing deletes `vendor_payables`).
   **Not closed by `sql/revoke-journal-mutations.sql`** — that file covers only
   `journal_batches`/`journal_entries`. Closing it is a policy decision: correct
   the trigger to treat "parent row already gone" as nothing-to-check, or write
   an explicit not-deletable message.
4. **T4-M2 / T4-M3 — the deferred constraint trigger's positive path is never
   exercised**, because `RefreshDatabase` rolls back before COMMIT, and one
   invariant test no longer reaches the branch it claims to cover. A `REVOKE`
   cannot close a coverage gap. Needs a non-transactional harness on PostgreSQL.
5. **T4-M5 — migration `2026_08_10_120300`'s `down()` is a no-op**, asymmetric
   with the migration it supersedes.
6. **AC14 — release rollback preserving journal + audit history has NO evidence
   of any kind.** Task 8 grepped the suite; every hit is a *transaction*
   rollback, which is a different thing. This needs a real decision at merge:
   build it, or accept it as risk explicitly. It must not ride through silently.
7. **AC7 is partial** — refund and chargeback are two of four operation types;
   refund is journal-shape-only and chargeback is a reserved value with no
   behaviour behind it.

### Authorization and identity gaps

8. **I5 — the re-authentication gate is login-recency, not step-up.**
   `last_authenticated_at` is written only at login; `MfaChallenge::submit()`
   never refreshes it. Owned by `fix/reauthentication-satisfy-wiring`, not this
   lane. **Amended 11 Aug 2026:** that lane's own review found the fix helps only
   a *stale* actor — a freshly-logged-in finance admin is waved through the
   middleware with no challenge raised, so no satisfied event is minted and the
   export's own gate then refuses them until their freshness lapses. Both
   directions fail closed, so this is a usability gap, not a security one, but it
   is the inverse of the intuitive expectation. **Do not describe the bulk export
   as "step-up protected" without this qualification.**
9. **`EnforceMfaChallenge` is attached to the export route but NOT TESTED in this
   lane** — no test binds `mfaState`, so every route test passes *through* that
   middleware without exercising it. It also currently routes MFA-enrolled admins
   into that middleware's known self-redirect loop, pending the reauth lane.
10. **N3 — `LedgerReport::summary($period, null)` is still a live unscoped-read
    shape**, reachable by no current caller. It is the residual hazard class of
    the Critical authorization finding (C1) and is carried here deliberately so
    it survives Task 9/10 slipping.
11. **The whole feature has never run against the real identity adapter.**
    `ActorContext::$roles` is always `[]`, so every authorized-path assertion in
    the suite goes through a bound fake. This is the correct fail-closed state —
    but it means this sign-off is doing real work, not rubber-stamping.

### Sequencing deviations to name explicitly in the PR body

12. **`JOURNAL_REVERSAL`** joined `SensitiveActions::ACTIONS` in Task 4, not Task
    6 as the plan anticipated (a review finding required reversal atomicity).
13. **`RECONCILIATION_EXCEPTION_RESOLVED`** was added in Task 5 under the
    user-approved Wave 1c ruling, beyond the single addition the plan's Global
    Constraints anticipated for this lane.

### Not executed — must stay that way

14. **`sql/revoke-journal-mutations.sql` has NOT been run** and must not be. It
    is blocked on finding N-1 (the host provisions one PostgreSQL role per
    environment, so there is no separate app role whose UPDATE/DELETE can be
    revoked). `ci/verify-docs.sh` GATE 13 keeps the file honest; two bypass tests
    assert the model-layer gap still exists so they fail loudly at the database
    once the role split lands.

### Cross-lane merge state (re-verify immediately before merging)

15. **Five files conflict with `lane/l3-payment-adapter`** as of L3 head
    `4e18276`, verified by comparing merge-base blobs on both sides:
    - `Money.php` — take either ordering (semantically identical; prefer L4's
      guard-before-compute).
    - `MoneyTest.php` — keep L4's `test_from_decimal_rejects_non_zero_excess_precision`
      (added for a Task 1 review finding); keep **exactly one** of the two
      byte-identical `PHP_INT_MIN` tests, which the two lanes named differently.
      A blind union duplicates that assertion.
    - `SensitiveActions.php` and `SensitiveActionsTest.php` — keep **both** lanes'
      actions. Never take either side wholesale; the test asserts the array as an
      exact list.
    - `routes/web.php` — adjacent appends (L4 bulk-export vs L3 payment-return),
      no semantic disagreement: **keep both blocks**.

    L3 is still actively pushing, so **re-run the `git merge-tree` simulation at
    merge time** rather than trusting this list.

16. **Merge order is L2 → L3 → L4, one at a time.** This is load-bearing for F15:
    `app/Domain/Booking/BookingDraftQuery.php:86` still carries
    `(float) $priceVersion->amount` on this branch and is fixed on L3's. Merging
    L4 first would put a float in the money path on the trunk. It also means the
    Verification item above — `grep -rn "(float)" app/Platform/FinancialLedger
    app/Domain/Booking` returning empty — **cannot pass on L4 alone**; it passes
    only once L3 has merged.

17. **E1 — L3's `LedgerSeamStub` teaches a false exception model.** It raises
    `InvalidArgumentException` where the real `Journal` surfaces a
    `QueryException`, for both the duplicate-business-key and balance
    properties. The contract's own Terms 2 and 4 make the real implementation the
    faithful party and the stub the divergent one. **Latent, not live** — no L3
    production code consumes the seam yet (verified by searching all of L3's
    `app/`), so the fix window is open and no L3 audit is required.
