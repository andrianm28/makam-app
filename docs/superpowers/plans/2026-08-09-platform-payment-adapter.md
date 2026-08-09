# Platform Payment Adapter — Implementation Plan (Lane L3, Wave 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `platform-payment-adapter` (`.kiro/specs/platform-payment-adapter/`) as a real `app/Platform/Payment/**` module against the **SumoPod sandbox** (ADR-0033): server-resolved `PaymentMode`, the six-condition payment guard as a single Action, hosted-checkout session creation with merchant/amount binding, durable webhook receiver (persist → validate → ack ≤ 2 s → async idempotent apply), manual fallback with `MENUNGGU_VERIFIKASI_PEMBAYARAN` + admin verification requiring recent re-authentication, balanced journal writes via the financial-ledger seam in the same transaction as the paid effect, and explicit non-destructive refund/chargeback/reversal — with `G-PAY-01` **staying closed for production** and the sandbox scoped to dev/staging.

**Architecture:** `PaymentAdapter` (overview.md §5) is the single boundary between domain workflows and the K3–K5 money path. Consumers request a session and observe state; they never talk to a provider directly. The provider is behind a `PaymentProvider` interface; the SumoPod sandbox is the dev/staging implementation (ADR-0033); production requires FIN-DEC + merchant setup + human sign-off and is NOT enabled by this lane. Money contract: **integer minor units (IDR × 100) everywhere** (Wave 0 ruling 0c, AC11-in-ledger) — no float anywhere in the payment path; this lane also resolves F15 at the Booking seam.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL 18, Pest/PHPUnit, Redis queue via Horizon (`critical` queue for webhook application per queue-and-outbox §2), Guzzle HTTP client (already present — no new dependency for HTTP; verify SumoPod SDK is not introduced — we talk HTTP directly).

---

## Current state — read this before planning any change

### What is already built

- `app/Platform/Payment/` contains only `.gitkeep` — the module does not exist.
- `PaymentMode` enum exists (`app/Platform/FeatureGate/Modes/PaymentMode.php`): `Online` / `ManualCoordination`, resolved server-side via `ModeResolver::paymentMode()` from `G-PAY-01` (AC1's "server-resolved, never a front-end flag" is already the established, tested pattern).
- ADR-0033 (Wave 0) fixes the provider choice and the sandbox contract surface for dev/staging: `POST https://api-pay-sandbox.sumopod.com/api/v1/payments` with `X-Api-Key`, QRIS supported, ≤ 24 h `expires_in_hours`, hosted `payment_link_url`, Svix signatures or `X-Webhook-Token` verification, 10 s ack deadline, events `payment.completed|failed|expired|test`.
- `docs/contracts/payment-webhook.md` v0.4 defines the application-side pipeline (`RECEIVED → VALIDATED → PROCESSED → DUPLICATE → REJECTED_* → RETRYABLE_FAILURE → MANUAL_REVIEW`) and the idempotency rule ("Primary key should use provider + event ID. A secondary guard should prevent the same provider transaction from settling multiple invoices.").
- `SensitiveActions::ACTIONS` already includes `PAYMENT_MANUAL_VERIFICATION` and `VENDOR_PAYOUT` (mandatory-reason actions) — the manual-verification and payout audit guards are pre-wired.
- `ReauthenticationService` (`app/Platform/IdentityAccess/Reauthentication/ReauthenticationService.php`) and the `RequireRecentAuthentication` middleware pattern exist (prepared, no real controller yet) — AC9's "recent re-authentication on manual verification" consumes this prepared mechanism.
- `Audit::record()/wrap()`, `Outbox::record()`, `OutboxClassification`, `OutboxQueueRouter` (with `critical` queue), `OutboxQueueName::Critical` all exist and are tested. `payment.received.v1` is in `event-catalog.md` (`:19`, "Valid webhook only").
- Ledger: `app/Platform/FinancialLedger/.gitkeep` only — the ledger does NOT exist. This lane depends on the L4 ledger seam (defined below), so the two lanes' contracts must agree. **Ordering:** L4's plan defines the `Journal` API (`journal_batches`/`journal_entries`, `business_key` idempotency, DB-enforced balance); L3 consumes it. If L4 lands later, L3's journal writes are implemented against the agreed `Journal` interface with a contract test; the two PRs merge in the order that satisfies both (see Task 7).
- `payment-webhook.md` specifies a 2-second ack. ADR-0033 specifies a 10-second ack deadline. **Resolution:** this lane targets the stricter, contract-driven 2-second ack (`payment-webhook.md` §5: "acknowledge it within 2 seconds") — the SumoPod 10 s limit is a ceiling, not a target. The receiver persists first and acks immediately; validation/application is async.

### Status / NOT TESTED

`platform-payment-adapter/tasks.md:30-32` is the authority: "Nothing here is implemented. The provider is **not chosen** and gate `G-PAY-01` status is unknown… `payment-webhook.md` describes the contract shape but no sandbox has been exercised. The FIN-DEC approvals in `release-gates.md` §H are prerequisites and are not granted."

This lane changes two of those three blockers: provider IS now chosen (ADR-0033) and the sandbox CAN be exercised (key injected into `.env.dev`). FIN-DEC for **production** stays ungranted; `G-PAY-01` stays closed in production. The sandbox is dev/staging-only and the module's default is `ManualCoordination`.

### What the spec requires (AC → design mapping)

| AC | Requirement (abridged) | Design surface |
|---|---|---|
| 1 | Server-resolve `PaymentMode`, never a front-end flag | `ModeResolver::paymentMode()` at session-request time; mode never accepted from request input |
| 2 | Session created only when all six guards pass | `GuardPaymentSession` Action (single Action, explicit denial result, no silent no-op) |
| 3 | `ONLINE` → hosted checkout, no raw card data | `PaymentProvider::createHostedSession()`; no card fields exist anywhere in the module |
| 4 | Paid only via validated webhook or approved manual verification; never browser return | Browser return URL is a view-only redirect; state transition functions live only in webhook/manual-verify Actions |
| 5 | Persist webhook, ack ≤ 2 s, then async process | `ReceiveWebhook` controller: store raw → validate signature/merchant/amount/replay → persist → 200 ack → dispatch async apply job on `critical` |
| 6 | Validate signature, merchant scope, amount, currency, replay window; reject-and-record on failure | `WebhookValidator`; every rejection writes `provider_events.status = REJECTED_*` + audit; never silently ignored |
| 7 | Idempotent by provider event identity; duplicates/out-of-order → exactly one set of effects | `provider_events` unique on `(provider, provider_event_id)`; secondary guard on `(provider, provider_transaction_id, invoice)` |
| 8 | Manual fallback: method, instructions, reference, optional proof upload → `MENUNGGU_VERIFIKASI_PEMBAYARAN`; admin verification is a separate authorized action | `SubmitManualPayment` + `VerifyManualPayment` Actions; document-kind `PAYMENT_PROOF` via document-vault (L1) |
| 9 | Manual verification requires recent re-authentication + audit | `RequireRecentAuthentication` middleware + `ReauthenticationService`; audit action `PAYMENT_MANUAL_VERIFICATION` (already sensitive) |
| 10 | Paid effect writes balanced journal in same transaction | `Journal::post()` (L4 seam) called inside the webhook-apply/manual-verify transaction, business key `payment:{provider_event_id}` |
| 11 | Payment failure exposes a recovery path; order and draft survive | Session-failure states never delete the draft; UI shows retry/manual-coordination per design-system §6.5 |
| 12 | Refund/chargeback/reversal = explicit operations, own authorization + journal effects, never mutate history | `ReversalService` with per-type Actions + audit + journal reversal batches (L4 reversing-batch pattern) |
| 13 | Merchant + `badan_usaha` bound on every session, re-bound on every webhook | `merchant_ref`/`badan_usaha_ref` on every `payment_sessions` row and compared in `WebhookValidator` |
| 14 | Credentials from secret management, env-scoped, never in logs | `config/payment.php` reads `SUMODOP_SANDBOX_API_KEY`/`SUMODOP_SANDBOX_WEBHOOK_SECRET`; redaction middleware for provider payloads |

## NOT TESTED (this lane)

- Production provider activation / real-money paths (requires FIN-DEC, merchant setup, human sign-off — out of scope).
- Live sandbox network calls in CI (no network to the sandbox from CI; the SumoPod provider adapter is contract-tested against a recorded fixture + a `FakeProvider`; an explicit manual smoke script exercises the real sandbox on the dev host — see Task 8).
- Automated payout (G-PAYOUT-01 closed; manual payout only, which is L4's scope).
- Chargeback/refund provider workflows against SumoPod (SumoPod sandbox may not exercise them; the module implements them against the `PaymentProvider` interface, contract-tested with `FakeProvider`, and ledgered as provider-untested until the sandbox supports it).

## Global Constraints

- `AGENTS.md` §Domain and financial invariants — each maps to a hard design rule here:
  - "Never create payment before valid confirmation/reservation, accepted quote, and authorized opening" → the six-condition guard is the ONLY path (AC2).
  - "Never mark paid from browser return URL" → AC4, enforced by code structure (no state-transition call reachable from the return route).
  - "Webhooks are durable, signed, merchant-scoped, amount-checked, replay-protected, and idempotent" → AC5/AC6/AC7.
  - "Closed online-payment gate uses manual fallback in Step 8" → AC8 + `ManualCoordination` default.
  - "Service payment and fulfillment are separate states" → module never touches fulfillment state.
  - "One reminder per grave/window, one invoice per cycle, one renewal settlement per period" → idempotency keys carry the domain key (secondary guard).
- Money is integer minor units (IDR × 100) end to end (Wave 0 ruling 0c). No float anywhere: request, session snapshot, webhook amount compare, journal. The SumoPod amount payload is compared as integer minor units after conversion; mismatch is `REJECTED_AMOUNT`.
- The six-condition guard denial returns an explicit explanatory result (design.md: "a denial returns an explanatory result, never a silent no-op") with a public-safe message; internal reason only in logs/audit.
- Webhook validation failure is recorded and rejected, never silently ignored (AC6); the record is the replay/audit source of truth (`provider_events` append-only).
- Provider credentials never in logs, error trackers, Pulse/Horizon tags, or queue payloads (AC14, `AGENTS.md` §Observability).
- Browser return/cancel URLs are view-only; no state transition, no "paid" claim, no journal effect reachable from them.
- No card data is ever handled (AC3 negative criteria).
- Refund/chargeback/reversal never mutate history — they post new reversal batches referencing the original (AC12 + ledger AC2).
- Capacity: worktree + staggered CI per Wave 0 S4-T9 baseline. Webhook processing runs on the `critical` queue (never starved by imports/reports).
- No new `SensitiveActions` entries in this lane beyond the two already present (`PAYMENT_MANUAL_VERIFICATION`, `VENDOR_PAYOUT`); `VENDOR_PAYOUT` is exercised by L4, not this lane.

## File Structure

New files under `app/Platform/Payment/`:

| File | Responsibility |
|---|---|
| `Contracts/PaymentProvider.php` | `createHostedSession(SessionRequest): SessionResult`, `getTransactionStatus()`, `refund()`, `verifyWebhookSignature(payload, headers)` |
| `Providers/SumoPodSandboxProvider.php` | ADR-0033 HTTP implementation (Guzzle), `X-Api-Key` header, amount in minor units, hosted link |
| `Providers/FakeProvider.php` | deterministic dev/CI provider for contract tests (no network) |
| `GuardPaymentSession.php` | AC2: the single six-condition guard Action |
| `CreatePaymentSession.php` | AC2/AC3/AC13: guard pass → provider session → `payment_sessions` row |
| `SessionState.php` | closed-list enum `CREATED|AWAITING_PAYMENT|PAID|FAILED|EXPIRED|REFUNDED` (payment-session scope, NOT order scope) |
| `ReceiveWebhook.php` | AC5: HTTP receiver — raw persist, validate, ack, dispatch |
| `WebhookValidator.php` | AC6: signature (Svix or token), merchant/badan_usaha, amount (minor units), currency, replay window |
| `ProcessWebhookEvent.php` | AC7: idempotent async apply (claim unique key → domain state → journal) |
| `ApplyWebhookEffect.php` | AC4/AC10: the only path that sets `PAID` from a webhook; writes journal in same transaction |
| `SubmitManualPayment.php` | AC8: method/instructions/reference/proof → `MENUNGGU_VERIFIKASI_PEMBAYARAN` |
| `VerifyManualPayment.php` | AC4/AC8/AC9: authorized admin verification, recent re-auth, audit, journal |
| `ReversalService.php` | AC12: refund/chargeback/reversal as explicit non-destructive operations |
| `Models/PaymentIntent.php`, `Models/PaymentSession.php`, `Models/ProviderEvent.php`, `Models/PaymentVerification.php`, `Models/PaymentReversal.php` | tables per design.md §Data |
| `Jobs/ProcessProviderEventJob.php` | async webhook application on `critical` queue |
| `Providers/SumoPodWebhookSignature.php` | Svix-compatible HMAC-SHA256 verification + `X-Webhook-Token` path |
| `PaymentServiceProvider.php` | binds provider by env (`sumpod-sandbox` for dev/staging, `fake` for CI/tests) |
| `Http/Middleware/RedactProviderPayload.php` | masks secrets/PII in provider payloads before logging |
| `Http/Controllers/WebhookController.php` | `/api/payments/webhook/{merchant}` route, merchant-scoped endpoint |

Migrations (all additive, `2026_08_09_*`): `create_payment_intents_table`, `create_payment_sessions_table`, `create_provider_events_table` (append-only, unique `(provider, provider_event_id)` + unique `(provider, provider_transaction_id, invoice_reference)`), `create_payment_verifications_table`, `create_payment_reversals_table`. A seed/config check verifies `SUMODOP_SANDBOX_API_KEY` present in dev (present in `.env.dev`, never in repo).

---

## Task 1: Money contract + F15 resolution at the Booking seam (Wave 0c)

**Files:** `app/Domain/Booking/Query/BookingDraftQuery.php` (F15 site), quote/total consumers, `config/money.php` (new)

- Replace `(float) $priceVersion->amount` and float `$total` with integer minor-unit handling. `price_versions.amount` is a decimal column today; introduce a `Money::fromDecimal(string $amount): int` (minor units) converter at the read seam so the price catalog's decimal storage converts deterministically to integer minor units on the way out; all downstream quote/total arithmetic is integer. No money value is stored as float anywhere (ledger AC11; Wave 0 ruling 0c).
- Update Booking's own tests that assert float totals.
- `config/money.php` centralizes `currency: IDR`, `minor_units: 2`.

- [ ] **Step 1:** Implement `Money` value object + `fromDecimal`/`toMinorInt`/`format`.
- [ ] **Step 2:** Replace the F15 `(float)` at `BookingDraftQuery` with `Money::fromDecimal()`; verify no float arithmetic remains on the quote/total path.
- [ ] **Step 3:** Update affected Booking tests; add a regression test pinning that a price version amount round-trips losslessly to integer minor units.
- [ ] **Step 4:** Commit with message citing Wave 0 ruling 0c (F15 resolved).

---

## Task 2: Payment guard + session creation (AC1, AC2, AC3, AC13)

**Files:** `GuardPaymentSession.php`, `CreatePaymentSession.php`, `Models/PaymentIntent.php`, `Models/PaymentSession.php`, `SessionState.php`

- `GuardPaymentSession::guard(...)` evaluates all six conditions in order and returns `GuardResult::PASS(reason)` or `GuardResult::DENIED(condition, publicMessage)`:
  1. product gate open (`FeatureGateResolver::isOpen('G-PAY-01')` for the relevant domain gate — actually the **mode** is the gate: `ModeResolver::paymentMode()` must be `Online` for an online session, but guard itself is mode-agnostic and always runs);
  2. confirmation valid OR reservation active (`Confirmation`/`PlotReservation` record states);
  3. quote accepted and unexpired (`quote.accepted` state + `expires_at`);
  4. authorized opening (`AuthorizePaymentOpening` — the actor holds opening permission for the order/case);
  5. `amount == quote total` (integer minor units);
  6. merchant + `badan_usaha` bound and explicit on the request.
  Each denial writes `payment_intents` (guard evaluation + decision record) + audit `PAYMENT_GUARD_DENIED` (outcome denied). Denials are observable (design.md §Observability: blocked early-payment attempts, guard denial reasons).
- `CreatePaymentSession`: guard pass → `PaymentProvider::createHostedSession()` (hosted checkout only, AC3) → persist `payment_sessions` with provider `payment_id`, `payment_link_url`, `expires_at`, `amount_minor`, `merchant_ref`, `badan_usaha_ref` → state `AWAITING_PAYMENT`. Provider unavailable at creation → truthful pending + manual-coordination offer when mode allows (§6.5), never a dead end (AC11).
- `ModeResolver::paymentMode()` is resolved at session-request time server-side (AC1); no request input can select a mode.

- [ ] **Step 1:** Implement the guard as a single Action with all six conditions + denial result shape.
- [ ] **Step 2:** Implement session creation via the provider interface.
- [ ] **Step 3:** Implement `payment_intents`/`payment_sessions` models.
- [ ] **Step 4:** Tests: all six guard failures each produce a denial (closed gate, expired quote, expired reservation, amount mismatch, unauthorized opening, missing merchant binding); a passed guard creates a session with `AWAITING_PAYMENT`; mode never read from request input; provider-unavailable returns truthful pending not a dead end.

---

## Task 3: Webhook receiver — persist, validate, ack ≤ 2 s (AC5, AC6, AC13)

**Files:** `ReceiveWebhook.php`, `WebhookValidator.php`, `Models/ProviderEvent.php`, `Providers/SumoPodWebhookSignature.php`, `Http/Controllers/WebhookController.php`, `Http/Middleware/RedactProviderPayload.php`, `Jobs/ProcessProviderEventJob.php`

- Route: `POST /api/payments/webhook/{merchant}` (merchant-scoped endpoint, AC13).
- `ReceiveWebhook` flow:
  1. Read raw body (never JSON-decoded-again after persist). Persist `provider_events` row immediately: `provider`, `provider_event_id`, `raw_payload` (private, encrypted at rest), `received_at`, `status = RECEIVED`. Unique `(provider, provider_event_id)` — a duplicate arrival collides on insert and short-circuits to a success ack (AC7 duplicate handling).
  2. `WebhookValidator::validate(event)`: signature (Svix `svix-*` HMAC-SHA256 over `{id}.{timestamp}.{rawBody}` using `whsec_…`, OR `X-Webhook-Token` match), timestamp skew within acceptable window, merchant/badan_usaha matches session, amount matches `payment_sessions.amount_minor` in integer minor units, currency IDR, invoice reference present.
  3. On validation failure: set `status = REJECTED_*`, `Audit::record(PAYMENT_WEBHOOK_REJECTED, outcome denied)`, still ack 200 (the provider doesn't need to know we rejected; the record is the truth, AC6 "record and reject"). NEVER silently ignored.
  4. On success: `status = VALIDATED`, ack 200 (≤ 2 s), dispatch `ProcessProviderEventJob` on `critical`.
- `RedactProviderPayload` middleware ensures raw payloads containing any credentials/PII are masked before any log/error-tracker exposure (AC14).
- Ack timing: persist is a single fast insert; validation is in-memory; the 2-second target is met by design (no async work in the request path).

- [ ] **Step 1:** Implement the receiver + route.
- [ ] **Step 2:** Implement the validator (signature, merchant, amount minor units, currency, replay window).
- [ ] **Step 3:** Implement `provider_events` model + redaction middleware.
- [ ] **Step 4:** Tests: bad signature → REJECTED_SIGNATURE recorded + acked; wrong merchant → REJECTED_MERCHANT; wrong amount (float/int mismatch) → REJECTED_AMOUNT; replay (old timestamp) → REJECTED; duplicate event id → short-circuit ack, one row; ack latency assertion (receiver does no async work in request path); raw payload never contains a credential in logs (redaction test).

---

## Task 4: Idempotent async apply — the only paid-from-webhook path (AC4, AC7, AC10, AC11)

**Files:** `ProcessWebhookEvent.php`, `ApplyWebhookEffect.php`, `SessionState.php`

- `ProcessWebhookEvent` (on `critical`): claim the `provider_events` row (`status = VALIDATED → PROCESSING` under lock, `SELECT ... FOR UPDATE` — the OutboxPublisher claim precedent). Re-check idempotency: `(provider, provider_transaction_id, invoice_reference)` secondary unique — prevents one transaction settling two invoices (payment-webhook.md "secondary guard").
- `ApplyWebhookEffect`:
  - `payment.completed`: verify session state is `AWAITING_PAYMENT`; set `payment_sessions.state = PAID` AND the domain order/invoice `DIBAYAR` state, and call `Journal::post(businessKey: "payment:{provider_event_id}", entries: [customer-receivable DR, payment-income CR] ...)` **in the same DB transaction** (AC10). Emit `payment.received.v1` outbox event (event-catalog `:19`, "Valid webhook only") → notifications lane (L2) + order workflow consume.
  - `payment.failed` / `payment.expired`: set session `FAILED`/`EXPIRED`; the order and draft survive (AC11); outbox `payment.failed.v1` (recovery-path consumers).
  - Out-of-order delivery: provider event ordering (completed vs expired) resolved by provider timestamp/order, not arrival order (design.md); an `expired` arriving after `completed` is a no-op (state already terminal), never a regression.
- The browser return/cancel routes (`success_return_url`/`cancel_return_url`) render a view only — they call no state transition, no journal, no "paid" claim (AC4 negative criteria; enforced by route-controller separation + tests).

- [ ] **Step 1:** Implement async apply job + claim.
- [ ] **Step 2:** Implement the paid transition with same-transaction journal write (against the agreed `Journal` interface — see Task 7 for L4 seam).
- [ ] **Step 3:** Implement failed/expired handling with draft survival.
- [ ] **Step 4:** Tests: `payment.completed` sets `DIBAYAR` + journal batch (business key `payment:{event_id}`) in one transaction; duplicate delivery posts once; out-of-order (`expired` then `completed`) yields exactly one paid effect; browser return route cannot reach a state transition (route test asserts no effect on session state); `payment.failed` leaves the draft intact and exposes a recovery path.

---

## Task 5: Manual fallback + admin verification (AC4, AC8, AC9)

**Files:** `SubmitManualPayment.php`, `VerifyManualPayment.php`, `Models/PaymentVerification.php`, `SensitiveActions` (already has `PAYMENT_MANUAL_VERIFICATION`)

- `SubmitManualPayment`: customer provides payment method, instructions, payment reference (bank/QRIS ref), optional proof upload. Proof routes through document-vault (L1) as kind `PAYMENT_PROOF` (quarantine → scan → accepted; reference-only in the verification record, never the file). Sets session/order status `MENUNGGU_VERIFIKASI_PEMBAYARAN` — a pending state, never rendered success (design-system §3.7). Audit `PAYMENT_MANUAL_SUBMITTED`.
- `VerifyManualPayment` (admin): a SEPARATE authorized action (AC8: "Admin verification SHALL be a separate authorized action"). Protected by `RequireRecentAuthentication` (AC9) — consumes `ReauthenticationService::challenge()`/`satisfy()` (the prepared mechanism). On approve: `MENUNGGU_VERIFIKASI_PEMBAYARAN → DIBAYAR` + journal in same transaction (AC10) + audit `PAYMENT_MANUAL_VERIFICATION` (sensitive → mandatory reason, e.g. "proof matched provider statement"). On reject: back to actionable state with reason + audit.
- `PaymentMode::ManualCoordination` (production default while `G-PAY-01` closed) renders the non-dismissible `<x-mk.alert intent=info>` banner in Step 8 per §6.9 — Step 8 is never removed.

- [ ] **Step 1:** Implement manual submission incl. proof-upload reference via document-vault seam.
- [ ] **Step 2:** Implement admin verification with re-authentication + audit.
- [ ] **Step 3:** Tests: submission sets `MENUNGGU_VERIFIKASI_PEMBAYARAN` (never success); verification without recent re-auth is blocked (middleware test); verification writes journal + audit with mandatory reason; reject path returns to actionable with reason; proof file is referenced, never attached.

---

## Task 6: Reversals — refund/chargeback/reversal (AC12)

**Files:** `ReversalService.php`, `Models/PaymentReversal.php`, `Actions/` per type

- `Refund`: authorized action (finance/admin), calls `PaymentProvider::refund()` when the sandbox supports it (else recorded manual refund), posts a journal reversal batch referencing the original `business_key` (`refund:{original_event_id}`) — never edits the original batch (ledger AC2). Audit `PAYMENT_REFUND` (non-sensitive? no — a financial action; add to `SensitiveActions` as `PAYMENT_REFUND` and `PAYMENT_CHARGEBACK` with mandatory reason — a financial/security change, human-gated at the merge boundary per `AGENTS.md` §Infrastructure-agent execution; Wave 0 approved the "explicit operations with own authorization" direction, this is the concrete list).
- `Chargeback`: explicit operation, journal reversal, audit, customer-balance effect.
- `Reversal` (general): same shape. None mutate `payment_sessions`/`provider_events` history — they append reversal records and journal batches.
- Provider-untested surface (SumoPod sandbox may not exercise refunds): contract-tested via `FakeProvider`, ledgered `NOT TESTED` against the live sandbox until the sandbox supports it.

- [ ] **Step 1:** Implement reversal service + per-type Actions.
- [ ] **Step 2:** Add `PAYMENT_REFUND`/`PAYMENT_CHARGEBACK` to `SensitiveActions` (human-gated at merge).
- [ ] **Step 3:** Tests: refund posts a reversal batch referencing the original and leaves history intact; chargeback effect; double-refund blocked (idempotent business key); reversal without authorization denied; mandatory reason enforced.

---

## Task 7: Ledger seam agreement (L3 ↔ L4 contract)

**Files:** `app/Platform/FinancialLedger/Contracts/Journal.php` (interface — co-owned with L4), L3's `tests/Contract/LedgerSeamContractTest.php`

- Agree the exact `Journal` interface both lanes implement/consume:
  - `Journal::post(string $businessKey, int $badanUsahaRef, string $sourceType, int|string $sourceId, array $entries /* [account, direction DR|CR, amountMinor int] */, ?string $correlationId, ?string $occurredAt): JournalBatch`
  - Precondition: entries balance (DB enforces; contract test asserts rejection on imbalance). Unique `business_key` (idempotent).
- This lane writes a contract test against a stub `Journal` so L3 is not blocked if L4 lands after; the real L4 implementation must satisfy the same contract test. Both PRs note the dependency; merge order resolves it (L4 first preferred; if L3 merges first, L3's `Journal` seam is the stub-verified contract and L4 replaces the binding).

- [ ] **Step 1:** Define the `Journal` interface file (one source of truth for both lanes).
- [ ] **Step 2:** Write the seam contract test (imbalance rejected, idempotent business key, same-transaction).
- [ ] **Step 3:** L3's webhook-apply and manual-verify Actions call `Journal::post` through the seam.

---

## Task 8: Sandbox smoke verification + doc updates

**Files:** `docs/superpowers/plans/2026-08-09-platform-payment-adapter.md` (this plan), `.kiro/specs/platform-payment-adapter/{tasks.md,traceability-matrix.md}`, `docs/planning/sprint-plan.md` (S8–9 row)

- Manual smoke script (dev host, not CI): create a payment session against the real SumoPod sandbox using `SUMODOP_SANDBOX_API_KEY` (present in `.env.dev`), confirm `payment_link_url` resolves, simulate the webhook flow with the sandbox's test event, confirm `provider_events` lifecycle. Record the smoke result in the plan's Task 8 report.
- Doc updates: mark spec tasks closed per traceability; append the sandbox-exercised evidence and the NOT-TESTED (production activation, live refunds) note to `sprint-plan.md` S8–9 row and `release-gates.md` if the gate row names this lane (verify the gate doc, don't assume).

- [ ] **Step 1:** Write + run the smoke script on the dev host (documented, recorded).
- [ ] **Step 2:** Update spec docs + sprint-plan S8–9 row.

---

## Task 9: Review slices, fix wave, re-review

### 9a. Task-scoped review slices (dispatched concurrently)

1. **Money/financial-invariants slice** — AC4, AC10, AC12 + Wave 0c: no float anywhere, paid only via webhook/manual-verify, same-transaction journal, reversals never mutate history, invoice-before-verification negative criteria.
2. **Webhook/security slice** — AC5, AC6, AC7, AC13, AC14: persist-then-ack ≤ 2 s, signature/merchant/amount/replay validation, idempotency + secondary guard, merchant binding, credential hygiene.
3. **Guard/fallback/UX slice** — AC1, AC2, AC3, AC8, AC9, AC11: six-condition guard, server-resolved mode, hosted checkout, manual fallback + `MENUNGGU_VERIFIKASI_PEMBAYARAN` never-success, re-auth on verification, recovery path, Step 8 never removed.

### 9b. Bounded fix wave + 9c. Scoped re-review + 9d. Doc correction

Per the two-tier review convention (Critical + Important fixed in one bounded wave with regression tests; Minor ledgered unless trivial; doc overclaims corrected).

---

## Task 10: Finish the branch

- [ ] Merge to trunk `docs/design-system-and-planning` via PR against the Wave 1 review checkpoint.
- [ ] Update `sprint-plan.md` S8–9 row — build complete with PR + CI run; production `G-PAY-01` still closed, sandbox evidence appended.
- [ ] Update `docs/planning/retrofit-backlog.md` §2 for surfaced findings.
- [ ] Verify static analysis, tests on PostgreSQL 18, Blade content-survival gate in CI (staggered per Wave 0 capacity baseline).

## Verification

- [ ] `vendor/bin/pest` green on PostgreSQL 18, including `tests/Feature/Payment/`, `tests/Unit/Platform/Payment/`, and the ledger seam contract test.
- [ ] Guard tests: all six conditions each produce a denial; passed guard creates `AWAITING_PAYMENT` session.
- [ ] Webhook tests: bad signature/merchant/amount/duplicate/replay/out-of-order/dead-dispatcher all covered; ack ≤ 2 s by design (no async in request path); duplicate yields one effect.
- [ ] No paid state reachable from browser return (route test).
- [ ] Journal written in the same transaction as paid effect (transaction-rollback test).
- [ ] No float on the money path (`grep -rn "(float)" app/Domain/Booking app/Platform/Payment` empty; `Money` used end to end).
- [ ] No provider credential in logs (redaction test); secret only in `.env.dev`/env, never in repo.
- [ ] Static analysis + lint clean; Blade content-survival gate passes.
