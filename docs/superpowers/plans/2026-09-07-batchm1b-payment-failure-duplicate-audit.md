# Batch M1b — payment failure & duplicate-audit remediation (PAY-01, PAY-02, PAY-03, PAY-07)

Phase 3 of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. Four
Medium audit findings, all about payment settlement paths that silently
swallow abnormal cases instead of auditing them. **Financial-affecting change
— human review is mandatory before merge (`AGENTS.md` §Infrastructure-agent
execution).**

## PAY-01 — duplicate-arrival audit for marketplace + care-subscription legs

`settleBooking()` (`ApplyPaymentSettlement.php`) already detects a second,
genuinely-independent settling payment against an already-`DIBAYAR` order by
comparing `order->paid_source_ref` (a stored column) against the current
event's `provider_transaction_id`, and calls `recordDuplicateArrival($event)`
inside the claim transaction when they differ.

`settleMarketplace()` and `settleCareSubscription()` have no such check —
`MarkMarketplaceOrderPaid`/`MarkCyclePaid` both swallow a second settlement
that lands on an already-paid target with no write of any kind.

**Deviation from the literal "compare against provider_transaction_id" shape,
documented:** neither `marketplace_orders` nor `subscription_cycles`/
`subscription_invoices` carries a `paid_source_ref`-equivalent column the way
`orders` does (verified by reading both create-table migrations). Adding one
is a schema change outside this batch's scope. The equivalent, schema-free
signal is used instead: read the target's paid state **before** calling
`MarkMarketplaceOrderPaid`/`MarkCyclePaid`. This is safe and equivalent to the
booking check because of what `ProcessWebhookEvent`'s claim already
guarantees: by the time `settle()` runs, THIS event's `(provider,
provider_transaction_id)` has never previously reached `PROCESSED` (a retry of
an already-`PROCESSED` event is refused earlier as `NotClaimable`, before
`settle()` is ever called). So if the target is already in its paid state
before this call, that paid state was necessarily produced by a *different*
provider transaction — exactly the fact the booking leg's column comparison
proves. Both branches read the pre-call state from the same row instance
`settle()` already resolved (inside the claim transaction, so no concurrent
writer can race it before the row lock is taken).

Files touched: `app/Platform/Payment/Actions/ApplyPaymentSettlement.php`.

## PAY-02 — permanently-failed settlement job: MANUAL_REVIEW + audit + admin queue

`ProcessProviderEventJob` retries a thrown settlement failure up to `$tries`/
`retryUntil()`, then lands in `failed_jobs` with the `provider_events` row
still `VALIDATED` — invisible to any operator unless someone is watching
`failed_jobs` directly.

Fix: a `failed(Throwable $e)` hook, run in its own transaction (there is no
ambient one left — the job's last attempt already rolled its own back):

1. Re-fetch the row `lockForUpdate()`. If it is not still `VALIDATED` (a
   concurrent successful claim, or an earlier failure hook already moved it),
   do nothing — never regress a row a later event already resolved.
2. `markStatus(ProviderEventStatus::ManualReview, <closed-list detail>)`.
3. `Audit::record()` in the exact shape
   `ProcessWebhookEvent::auditSettlementConflict()` already uses: subject =
   the `provider_events` row, `AuditOutcome::Denied`, no actor (a webhook
   holds no credential of ours), `AuditSource::Job` (this runs on the queue
   worker, not through the HTTP `api` path), closed-list `note` — no
   exception message, no payload value (AC14: an exception message here could
   contain any amount/id from the underlying failure).

New constant: `PaymentAuditActions::SETTLEMENT_PERMANENTLY_FAILED`.

**Admin surfacing:** grepping the whole app for `ManualReview` found no
existing admin-panel consumer of a `MANUAL_REVIEW` status anywhere — this is
new territory, not a retrofit of an existing pattern. The closest real
precedent for "a financial exception queue widget, authorized and scoped the
same way" is `App\Filament\Admin\Widgets\FailedPaymentExceptionQueueWidget`
(gated by `LedgerReadAuthorizer`, scoped to the actor's granted
`entityRefs`). `provider_events` carries no `entity_ref`/`badan_usaha_ref`
column of its own (only `merchant_ref`), so the new widget scopes through the
one join that exists: `payment_sessions.badan_usaha_ref` via
`provider_transaction_id = payment_sessions.provider_payment_id`. A
`MANUAL_REVIEW` row with no matching session (should not happen in practice —
`resolveSessionOrFail()` runs before any anomaly branch) is excluded rather
than shown unscoped, fail-closed.

New file: `app/Filament/Admin/Widgets/PaymentSettlementManualReviewQueueWidget.php`.

Files touched: `app/Platform/Payment/Jobs/ProcessProviderEventJob.php`,
`app/Platform/Payment/PaymentAuditActions.php`.

## PAY-03 — renewal anomaly audit rows erased by the enclosing rollback

`MarkRenewalPaidOnline`'s own doc block already diagnoses the root cause in
detail (24 Aug 2026 final-review note): its `DB::transaction()` is a
SAVEPOINT nested inside `ProcessWebhookEvent`'s real transaction on the only
production call path. When the genuine-anomaly branch throws
`RenewalAlreadySettledException`, the `catch` outside `DB::transaction()`
writes an audit row that lands inside the *still-open outer* transaction —
then the exception keeps propagating, the outer transaction rolls back, and
that row is erased with everything else. The amount-mismatch branch
(`RenewalPaymentAmountMismatchException`) has no `catch`-based audit write at
all, so it never had a row to begin with.

**Fix, per the finding's own direction ("record-and-return-an-outcome rather
than record-then-throw", the shape PAY-02 establishes for
`auditSettlementConflict()`):** `MarkRenewalPaidOnline` no longer throws
either exception. Both anomaly branches now **return** a value
(`App\Platform\Payment\SettlementAnomaly`, a small closed value object:
`auditAction`, `note`, `rejectionDetail`, optional `subject`) instead of
throwing, so nothing ever unwinds out of the one transaction the method runs
in — there is nothing left to roll back, so the write-nothing precondition
each anomaly still enforces is preserved (no renewal row change, no outbox
row) but the ABILITY to record an audit trail no longer depends on which
transaction happens to be outermost.

`ApplyPaymentSettlement::settle()` / `settleRenewal()` change their return
type from `void` to `?SettlementAnomaly` (`null` = settled normally — every
other leg, booking/marketplace/care-subscription, is unaffected and always
returns `null` on success, since PAY-03's scope is the renewal leg only).
`ProcessWebhookEvent::__invoke()` checks the returned anomaly: if non-null, it
moves the row to `MANUAL_REVIEW` with the anomaly's `rejectionDetail`, writes
one `Audit::record()` call using the anomaly's `auditAction`/`note`/`subject`
(subject defaults to the `provider_events` row, but `MarkRenewalPaidOnline`
supplies the `renewal` subject so an operator can still jump straight to the
renewal, matching what the erased rows used to point at), and returns a new
`ProcessWebhookEventOutcome::SettlementAnomaly` case **instead of** marking
the row `PROCESSED` or transitioning the session to `Paid` — this commits
inside the SAME transaction that claimed the row, which is exactly what makes
the audit row durable now.

New constant: `RenewalAuditActions::RENEWAL_PAID_ONLINE_AMOUNT_MISMATCH`
(the amount-mismatch branch never had an audit action before this fix).
`RenewalAuditActions::RENEWAL_PAID_ONLINE_REFUSED` is reused for the
already-anomalous-status branch — same meaning as before, just written from a
different (now-committing) call site.

`RenewalAlreadySettledException` / `RenewalPaymentAmountMismatchException`
are **not deleted** — `Actions\MarkRenewalPaidExternally` and
`Actions\ExpireRenewal` still use `RenewalAlreadySettledException` on their
own (admin-triggered, single-actor, non-webhook) call paths, which PAY-03
does not touch; that throwing shape is still correct there per
`MarkRenewalPaidOnline`'s own doc block ("`MarkRenewalPaidExternally`'s own
throwing shape is NOT the right precedent here").

**Test rewrite required and expected:** `MarkRenewalPaidOnlineTest`'s two
anomaly tests (`test_a_settlement_attempt_against_a_kedaluwarsa_renewal_still_refuses`,
`test_a_mismatched_amount_refuses_and_writes_nothing`,
`test_a_mismatched_amount_against_an_already_paid_renewal_still_refuses_on_amount`)
currently assert a thrown exception; they are rewritten to assert a returned
`SettlementAnomaly` instead, since throwing is no longer this Action's
contract for these two branches. `RenewalWebhookSettlementTest`'s wrong-amount
test is rewritten to assert the new commit-in-place behaviour (`MANUAL_REVIEW`
+ audit row + HTTP 200, no retry) instead of a propagated exception + row
stuck at `VALIDATED`.

Files touched: `app/Domain/Renewal/Actions/MarkRenewalPaidOnline.php`,
`app/Domain/Renewal/RenewalAuditActions.php`,
`app/Platform/Payment/Actions/ApplyPaymentSettlement.php`,
`app/Platform/Payment/ProcessWebhookEvent.php`,
`app/Platform/Payment/ProcessWebhookEventOutcome.php`.
New file: `app/Platform/Payment/SettlementAnomaly.php`.

## PAY-07 — `MarkOrderPaid`'s `$reason` never reaches the audit trail

`MarkOrderPaid::__invoke()` accepts `?string $reason` but never passes it
into `PaidTrigger`/`ApplyPaidEffects`, so an admin's money attestation reason
is silently discarded — `RecordOrderStatusChange` (the eventual audit writer
for the DIBAYAR transition) never sees it.

Fix:
1. `PaidTrigger` gains a nullable `$reason` field, forwarded by
   `ApplyPaidEffects::apply()` into `RecordOrderStatusChange`'s existing
   `?string $reason` parameter. `MarkOrderPaid` now passes its own `$reason`
   into the `PaidTrigger` it builds; the webhook trigger site
   (`ApplyPaymentSettlement::settleBooking()`) is unaffected and keeps
   passing none.

2. **`SensitiveActions::ACTIONS` is deliberately NOT touched — a documented
   deviation from the literal instruction, found while implementing it.**
   `orders.status = DIBAYAR` is written through ONE shared writer
   (`RecordOrderStatusChange`) from TWO independent trigger sites: this
   admin path, and the machine-driven webhook path
   (`ApplyPaymentSettlement::settleBooking()`, which legitimately passes no
   reason — a validated webhook has no human-authored justification to
   carry). `RecordOrderStatusChange::record()`'s own convention names the
   audit action `$to->value` (i.e. the literal string `'DIBAYAR'`) whenever
   that value is on `SensitiveActions::ACTIONS`, and `Audit::record()`'s
   mandatory-reason check then fires for BOTH trigger sites, not just the
   admin one — there is no way to add `'DIBAYAR'` to that list without also
   making every real webhook-settled booking payment throw
   `AuditReasonRequiredException`, which would break live payment
   processing. That is exactly the kind of production-breaking regression
   `AGENTS.md` §Infrastructure-agent execution's "human review is mandatory
   before ... financial ... changes" exists to catch before merge — flagged
   here rather than implemented. `SensitiveActionsTest`'s exact-list
   assertion is therefore also left untouched.

   The mandatory-reason requirement is instead enforced narrowly, scoped to
   exactly `MarkOrderPaid` (never the webhook path): a blank `$reason` throws
   `InvalidArgumentException` via `Audit::reasonIsBlank()`, mirroring
   `RecordOrderStatusChange::record()`'s own per-call-site blank check for
   `DITOLAK` rather than the shared cross-trigger `SensitiveActions` list.

3. A required `Textarea` for the reason is rendered in
   `TransitionOrderAction.php` for the `DIBAYAR` transition specifically (a
   new `elseif` branch, since `OrderStatus::requiresReason()` — deliberately
   also left untouched, for the identical cross-trigger reason as (2) above —
   only covers `DITOLAK`), matching the existing
   `MENUNGGU_VERIFIKASI_PEMBAYARAN` branch's pattern.

4. **Evidence/payment-reference field: NOT added in this batch, reasoning
   recorded here.** `MarkRenewalPaidExternally` requires a dedicated
   `$evidence` argument backed by its own `renewal_external_markings` table —
   a real evidence artefact for money that moved OUTSIDE the platform, with
   no other record of it anywhere. `MarkOrderPaid`'s admin path has no
   comparable gap: the transition is already gated by
   `ReauthenticationGuard::assertFresh()` (fresh password re-auth for every
   money transition, `TransitionOrderAction::run()`), and the required
   reason `Textarea` IS the recorded justification, the same free-text-reason
   shape `MENUNGGU_VERIFIKASI_PEMBAYARAN`'s own "Catatan pembayaran" field
   already uses for a comparable manual-payment attestation on this exact
   resource. Adding a dedicated evidence table for bookings the way
   `renewal_external_markings` does for renewals is a schema change outside
   this batch's stated scope — the same category of deviation PAY-01
   documents above for `marketplace_orders`/`subscription_cycles`.

Files touched: `app/Domain/OrderWorkflow/PaidTrigger.php`,
`app/Domain/OrderWorkflow/Actions/ApplyPaidEffects.php`,
`app/Domain/OrderWorkflow/Actions/MarkOrderPaid.php`,
`app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php`,
`app/Support/ExampleData/BookingOrderExampleData.php` (its one real
`MarkOrderPaid` call site needed a reason once the blank check landed).

## Verification

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (bumped memory limit as needed)
- `bash ci/verify-docs.sh`
- Full Docker-based test suite against real PostgreSQL (host PHP is 8.3, too
  old) — `m1b-*`-prefixed disposable containers.

Every check result reported honestly below (`PASS`/`FAIL`/`BLOCKED`/`NOT
TESTED` — never a fabricated `PASS`).
