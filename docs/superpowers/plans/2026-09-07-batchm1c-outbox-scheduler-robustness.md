# Batch M1c — Outbox/Scheduler Robustness + Payment Return UX

- **Date:** 2026-09-07
- **Branch:** `fix/batchm1c-outbox-scheduler-robustness`
- **Parent program:** Phase 3 audit remediation (`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` — referenced by the batch brief; not present in this worktree at plan time, presumably owned by a sibling lane's branch. This plan is self-contained and does not depend on that document existing here.)
- **Findings covered:** QUE-04, QUE-07, QUE-09, PAY-04 (all Medium)

## Summary

Four related Medium findings, all about the outbox/scheduler pipeline losing
visibility or availability under failure, plus one small payment-UX bug in
the same neighbourhood (payment return pages).

## QUE-04 — outbox events lost after a permanently-failed publish job

### Problem

`OutboxPublisher::dispatchOne()` stamped `dispatched_at` immediately after
handing `PublishOutboxEventJob` to the queue driver — before the job had run
at all. If the job then failed every retry (bad payload, listener
exception, crash-looping worker), the row was left permanently
`dispatched_at IS NOT NULL` with `locked_at` already cleared: invisible to
`OutboxPublisher::claim()`'s reclaim query (`dispatched_at IS NULL`),
invisible to `SpineWatchdogCommand`'s stale-outbox check (same predicate),
and with no replay path. The event was gone.

### Fix chosen

Option 1 from the batch brief: move the `dispatched_at` stamp into
`PublishOutboxEventJob::handle()`, after `Event::dispatch()` actually runs.
`locked_at` (set by `claim()`) is now the in-flight marker for the window
between "claimed" and "actually published" — `dispatchOne()` no longer
touches it on success at all. It is cleared in two places:

1. `PublishOutboxEventJob::handle()`, on success, alongside the
   `dispatched_at` stamp.
2. A new `PublishOutboxEventJob::failed(Throwable $exception)` hook, once
   the queue/worker configuration (Horizon's per-supervisor `tries`)
   exhausts retries. This clears `locked_at`, records `last_error`, and
   advances `attempt_count`/`available_at` using
   `OutboxPublisher::backoffSeconds()` — the SAME formula
   `dispatchOne()`'s own catch block already uses for a dispatch-time
   failure, kept in one place.

A worker hard-killed mid-`handle()` (never reaching either path) still
self-heals through the pre-existing stale-claim reclaim
(`OutboxPublisher::STALE_CLAIM_SECONDS`, 5 minutes) — unchanged.

Why option 1 over option 2 (a `failed()`-only fix that leaves the
dispatch-time stamp alone): option 1 makes "dispatched" mean what the column
name says everywhere, not just after `failed()` finally runs. Option 2 alone
would still leave a permanently-failed job's row reading
`dispatched_at IS NOT NULL` between the failure and whenever `failed()`
executes (Laravel calls `failed()` synchronously on the final attempt, so in
practice this window is tiny, but option 1 removes the ambiguity
structurally rather than by timing). Both a `failed()` hook AND the stamp
move landed, because the brief allowed "pick one" for the STAMP location but
a `failed()` hook is independently useful either way: it turns a 5-minute
stale-claim wait into an immediate reclaim.

### Bounded replay command

`docs/architecture/queue-and-outbox.md` §8 requires "Manual replay requires
privileged permission, reason, and audit" and no such command existed
(checked `app/Console/Commands/` for `outbox:*` before building — only
`outbox:publish` existed). New: `php artisan outbox:replay {ids*}
{--reason=}` (`App\Console\Commands\OutboxReplayCommand`):

- **Privileged:** console-only, no HTTP/Filament wrapper, following the
  exact `identity:grant-role` pattern (`IdentifiesConsoleOperator` for
  OS-account attribution, `RequiresAuditReason` for the blank-reason
  usability check).
- **Reason-required:** `--reason` is validated non-blank before anything
  runs; `Audit::record()`'s own check (now backed by
  `SensitiveActions::ACTIONS` including the new `OUTBOX_EVENT_REPLAY` entry)
  is the authoritative enforcement underneath.
- **Bounded:** explicit `outbox_events.id` values only — no time-window,
  status, or event-name filter accepted at all. Capped at
  `OutboxReplayCommand::MAX_IDS_PER_INVOCATION` (50) ids per invocation.
- **Audited:** one `OUTBOX_EVENT_REPLAY` audit event per row actually
  replayed, via `Audit::wrap()` (mutation + audit in one transaction).
- Only replays rows that have not already published
  (`dispatched_at IS NULL`); an unknown or already-published id is reported
  and skipped, never silently ignored and never a reason to abort the rest
  of the batch.
- The mutation itself: clear `locked_at`, set `available_at = now()`. No
  bypass of `OutboxPublisher`/`PublishOutboxEventJob` — the next
  `outbox:publish` tick claims and republishes it through the normal path.

### Watchdog signal

`SpineWatchdogCommand` gets a new check, `checkStuckInFlightOutbox()`
(alongside the existing `checkStaleOutbox()` around the command's line 96):
rows where `dispatched_at IS NULL AND locked_at IS NOT NULL AND locked_at <
now() - N minutes` (`--stuck-outbox-minutes`, default 10). This is the
"dispatched but never consumed" signal the batch brief names: a row that
`dispatchOne()` claimed and handed to the queue driver, but that never
completed publishing. It is deliberately keyed on `locked_at`, not
`occurred_at` like `checkStaleOutbox()`: the existing check eventually
catches this too (it ages off row creation time, independent of claim
state), but cannot distinguish "never claimed at all" (scheduler/publisher
not running) from "claimed, queued, and stuck" (a crash-looping worker or a
permanently-failed job) — two different causes needing different responses.
The new check surfaces the second case immediately rather than waiting for
`checkStaleOutbox()`'s occurred_at-based window to also trip.

## QUE-07 — 24-hour scheduler mutex default

`routes/console.php`'s `withoutOverlapping()` calls for `outbox:publish`,
`plot-reservation:expire-stale-draft-holds`, and `spine:watchdog` had no
explicit expiry, defaulting to Laravel's 1440-minute (24-hour) mutex. One
ungraceful kill of a scheduled run silences that job for up to a day.

Fix: explicit expiries as specified in the brief —
`outbox:publish` and `plot-reservation:expire-stale-draft-holds` at
`withoutOverlapping(5)`, `spine:watchdog` at `withoutOverlapping(10)`
(slightly longer, matching its own 5-minute-longer cadence). The new
`documents:reconcile-storage-cleanup` entry (QUE-09, below) also gets an
explicit `withoutOverlapping(30)`, sized to its own bounded but longer
runtime, rather than being left on the 24-hour default either.

Manual recovery documented in `docs/architecture/queue-and-outbox.md` §9:
`php artisan schedule:clear-cache` clears every cached overlap mutex on the
host immediately, for use if a mutex is ever suspected stuck before its
expiry naturally lapses.

## QUE-09 — undispatched storage-cleanup recovery job

`App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob` is
documented on its own class as a "recovery entry point for
scheduler/worker supervision" but had no dispatcher anywhere in the
application (`grep -rn "ReconcileDocumentStorageCleanupJob" app` found only
the class's own file before this batch).

Fix: a thin new command, `App\Console\Commands
\DocumentsReconcileStorageCleanupCommand` (`documents:reconcile-storage-
cleanup`), scheduled hourly with `withoutOverlapping(30)`. A command wrapper
rather than `Schedule::job()` directly, so an operator also has a named
`artisan` entry point to run reconciliation on demand — the same code path
the schedule uses.

**Dependency called out explicitly, not silently worked around:** the job
dispatches its own recovery work onto the `media` queue (matching
`ScanDocumentJob` and the rest of `DocumentVault\Jobs\*`, per
`docs/superpowers/plans/2026-08-09-platform-document-vault.md`). QUE-01 (a
separate, out-of-scope host-infra finding) notes the `media` queue
currently has no consumer running in beta. This batch still lands the
scheduler wiring: it is correct regardless of whether beta's worker fleet
is fixed yet, and starts draining automatically the moment a `media` worker
exists.

## PAY-04 — payment return pages can never show paid/failed state

### Problem

`BookingWizard::openOnlinePayment()` (or its equivalent method — see file
list below), `Checkout`, and `RenewalPayment` all called
`route('payments.return')`/`route('payments.cancel')` with no query
parameters when opening a payment session. `PaymentReturnController`/
`PaymentCancelController` read a `session` query key
(`ReturnPageState::fromRequest($sessionKey, $providerPaymentId)`) to decide
which `payment_sessions` row to describe. With no `session` (and no
provider-echoed `payment_id`, which some providers omit or place
differently), the return page can never resolve a real session and always
renders the generic pending state — even for a payment that genuinely
succeeded or failed.

### Fix

Confirmed safe by construction before touching anything:
`ReturnPageState::fromRequest()`'s own doc block states the `session`/
`payment_id` query keys are display-only SELECTORS — "which session is
displayed, never what the page says about it" — and the copy always comes
from the session row's own webhook-written `state` column. Passing a
caller-known UUID on the URL cannot let a customer manufacture a fake paid
page.

The session id does not exist yet at the point `successReturnUrl`/
`cancelReturnUrl` must be built (they are inputs to
`OpenPaymentSessionCommand`, which creates the `PaymentSession` row further
downstream). Chose the "pre-generate the id" option from the brief over
"re-issue the provider link with a real id after the fact": the latter
would require a second round trip to the payment provider (or a
provider-side link-update call that may not exist) purely to correct a URL
already embedded in the first hosted-checkout link — pre-generating avoids
that entirely.

Implementation:

1. `OpenPaymentSessionCommand` gains an optional `?string $sessionId`
   parameter.
2. `OpenPaymentSession::__invoke()` passes `'id' => $command->sessionId`
   into `PaymentSession::create()`. `PaymentSession` uses `HasUuids`, whose
   `creating` hook only generates its own id when the attribute is unset —
   `isset()`/`empty()` both treat a `null` value as unset, so `sessionId:
   null` (every existing caller before this batch, and any future one that
   doesn't care) is byte-for-byte the old behaviour.
3. All three call sites —
   `app/Livewire/Public/Booking/BookingWizard.php`,
   `app/Livewire/Public/Marketplace/Checkout.php`,
   `app/Livewire/Public/Renewal/RenewalPayment.php` — now do:
   ```php
   $paymentSessionId = (string) Str::uuid();
   // ...
   successReturnUrl: route('payments.return', ['session' => $paymentSessionId]),
   cancelReturnUrl: route('payments.cancel', ['session' => $paymentSessionId]),
   sessionId: $paymentSessionId,
   ```
   so the id embedded in the return URL is the exact id the created row will
   carry.

No change to `PaymentReturnController`, `PaymentCancelController`, or
`ReturnPageState` — they already read and trust only the `session` selector
correctly; they just never received a value before this fix.

## Testing

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (memory limit raised as needed)
- `bash ci/verify-docs.sh`
- Existing outbox suite (`tests/Feature/Outbox/*`) re-run against real
  Postgres — `OutboxRecoveryTest`, `OutboxPublisherClaimTest`,
  `OutboxPublisherClaimTwoConnectionTest`, `OutboxPublishCommandTest` all
  assert `dispatched_at`/`locked_at` transitions that the QUE-04 change
  touches directly; none needed edits, since `QUEUE_CONNECTION=sync` in
  tests still runs `PublishOutboxEventJob::handle()` inline within
  `dispatchOne()`'s `dispatch()` call, so the stamp still lands by the time
  `publishBatch()` returns.
- New/updated coverage this batch adds (see PR diff for exact test files):
  outbox replay command (bounded cap, reason requirement, skip-already-
  published, audit row written), `PublishOutboxEventJob::failed()`
  reclaiming a row, `SpineWatchdogCommand`'s new stuck-in-flight signal, and
  a payment-session-id-round-trips-to-the-return-URL test for at least one
  of the three call sites (the other two share the identical shape).
- `docs/operations/dev-staging-environment.md`'s combined dev/staging
  cron-based scheduler is the real target for the `withoutOverlapping()`
  expiries and `documents:reconcile-storage-cleanup` schedule entry —
  verified structurally (routes/console.php parses, commands resolve) since
  a live cron tick is not exercisable from this worktree.

## Out of scope

- QUE-01 (media queue has no consumer in beta) — separate host-infra
  finding, explicitly not fixed here; only referenced as a known
  dependency.
- Any change to `PaymentReturnController`/`PaymentCancelController`/
  `ReturnPageState` beyond confirming they already handle the `session`
  parameter correctly — they needed no code change.
