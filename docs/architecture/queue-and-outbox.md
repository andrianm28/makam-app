# Queue Topology and Transactional Outbox — v0.4

## 1. Decision

Use Laravel Queue backed by managed Redis and operated through Laravel Horizon. Use a **transactional outbox** for business events whose loss would create inconsistent financial, operational, or customer state.

## 2. Queue topology

| Queue | Purpose | Initial priority | Examples |
|---|---|---:|---|
| `critical` | Financial callbacks and state propagation | 1 | payment webhook processing, journal/invoice trigger, reconciliation dispatch |
| `urgent` | At-Need/Urgent operations | 2 | case alerts, overdue escalation, operator fallback |
| `notifications` | External messages | 3 | email, WhatsApp, in-app fan-out |
| `default` | Normal asynchronous work | 4 | routine event listeners, cache refresh |
| `imports` | Large data batches | 5 | 10,000-row grave import, validation report |
| `media` | File and image processing | 5 | malware scan, preview generation, metadata extraction |
| `reports` | Slow exports/aggregations | 6 | finance/admin report exports |

Large imports or reports must never share the only worker pool with payment or Urgent work.

## 3. Horizon supervisor baseline

```text
supervisor-critical: critical          min 1, max 4, timeout 60s
supervisor-urgent:   urgent            min 1, max 4, timeout 60s
supervisor-notify:   notifications     min 1, max 4, timeout 90s
supervisor-default:  default           min 1, max 4, timeout 90s
supervisor-batch:    imports,media     min 1, max 3, timeout job-specific
supervisor-reports:  reports           min 1, max 2, timeout job-specific
```

> **Corrected 24 Aug 2026** (`docs/testing/release-gates.md` §H, Task 7): `supervisor-batch`/`supervisor-reports` originally said `min 0` here, intending true zero-idle capacity. That value is invalid for the Horizon version this project runs — `Laravel\Horizon\ProvisioningPlan::convert()` throws unconditionally when any environment's `minProcesses` is below 1 — and `config/horizon.php` was found, by actually running `php artisan horizon` for the first time since it was authored, to crash on startup in every environment because of it. `SupervisorOptions`'s own package default for `minProcesses` is already `1`, not `0`, so zero-idle scaling was never achievable here regardless; `min 1` above is the real floor, not a lowered target. Real capacity consequence: these 2 supervisors now hold at least 1 permanently-resident worker process each in production, not scale-to-zero, on the real production host — `yiemvm`, 8 vCPU/31 GB (per `ADR-0027`'s own 23 Aug 2026 correction of this same document's superseded "2 vCPU/4 GB" title/figure; production runs on this same shared host under that ADR's single-host decision, not a separate smaller one). If a future host resize makes this consequential, it needs a real capacity assessment, not a return to `min 0` — that value crashes Horizon outright, it does not save capacity.

Exact process counts are capacity settings, not code constants. Production requires long-wait alerts per queue. Suggested initial thresholds:

```text
critical:      10 seconds
urgent:        15 seconds
notifications: 60 seconds
default:       90 seconds
imports:       300 seconds
media:         300 seconds
reports:       600 seconds
```

## 4. Redis topology

- Managed primary/replica with provider failover is preferred.
- Horizon is not used with Redis Cluster.
- Separate logical connections/prefixes for cache, session, queue, Horizon, and locks.
- Queue payload must not contain raw private documents, secrets, or unnecessary personal data.
- Redis is not the source of truth for financial or booking state.

## 5. Transactional outbox

### Problem prevented

```text
Database commit succeeds
→ process crashes before queue dispatch
→ order is paid but invoice/notification/listener never runs
```

### Write path

```text
BEGIN DATABASE TRANSACTION
  mutate aggregate
  insert immutable state event
  insert outbox record
COMMIT

outbox publisher
  claim pending rows
  dispatch queue/event
  mark dispatched
```

### Minimum outbox schema

```text
outbox_events
- id UUID/UUIDv7
- event_name
- event_version
- aggregate_type
- aggregate_id
- payload JSONB
- classification
- occurred_at
- available_at
- attempt_count
- locked_at
- dispatched_at
- last_error
- trace_id
- idempotency_key UNIQUE
```

Restricted document content and permanent object keys are prohibited in payloads.

## 6. Events requiring outbox

At minimum:

- `availability.confirmed`;
- `quote.accepted`;
- `payment.received`;
- `order.status_changed` when externally consumed;
- `renewal.paid_or_verified`;
- `vendor.order_assigned`;
- `vendor.work_completed`;
- `certificate.issued`;
- `grave.reminder_due`;
- `care.cycle_created`.

Pure cache invalidation may use ordinary post-commit listeners.

## 7. Delivery semantics

The system assumes **at-least-once delivery**. Consumers must be idempotent using `event_id` or a domain-specific idempotency key. “Exactly once” is achieved only at the business-effect level through database constraints and consumer records.

## 8. Retry and failure

- Use bounded exponential backoff with jitter for external providers.
- Do not retry permanent validation/authorization errors.
- Failed critical events enter an exception queue and alert after threshold.
- Outbox rows are retained long enough for audit/replay policy.
- Manual replay requires privileged permission, reason, and audit.

Batch M1c (QUE-04) lands the manual replay command this section calls for: `php artisan outbox:replay {ids*} {--reason=}` (`App\Console\Commands\OutboxReplayCommand`). Console-only (no HTTP/Filament surface), bounded to explicit `outbox_events.id` values named on the command line (at most `OutboxReplayCommand::MAX_IDS_PER_INVOCATION` per invocation — never an unbounded filter), requires a non-blank `--reason`, and writes an `OUTBOX_EVENT_REPLAY` audit event per row replayed. It only acts on rows that have not already published (`dispatched_at IS NULL`); an already-published id is reported and skipped. Use it once `spine:watchdog`'s "dispatched but never consumed" signal (§SpineWatchdogCommand's own doc block) or a `failed_jobs` entry for `PublishOutboxEventJob` has identified the specific stuck row(s) — this command does not discover them itself.

Batch M1c (QUE-04) also moved the `dispatched_at` stamp from the moment `OutboxPublisher::dispatchOne()` hands a job to the queue driver to the moment `PublishOutboxEventJob::handle()` actually fires `OutboxEventPublished` — see that job's own class doc block. Before this fix, a job that was queued but then failed every retry left its row permanently `dispatched_at IS NOT NULL` with the event never truly published: invisible to the reclaim query above, invisible to the watchdog, and unreplayable (`outbox:replay` refuses an already-"dispatched" row on purpose). The job's `failed()` hook now clears the row's claim and advances its backoff the moment retries are exhausted, rather than relying solely on the stale-claim timeout.

## 9. Deployment and shutdown

Deployment must terminate Horizon gracefully so active jobs finish or are safely retried. Job timeout must be shorter than `retry_after`. Scheduler runs a single outbox publisher using overlap prevention or distributed lock.

Resolved numbers (final-review I1, observability-and-adr-fixes): `critical`/`urgent`/`notifications`/`default` supervisors use the `redis` queue connection (`retry_after` 90s, `config/queue.php`) against 60–90s job timeouts (`config/horizon.php`). `supervisor-batch` (`imports`, `media`) and `supervisor-reports` run at a 900s job timeout, which exceeds 90s — those two supervisors use a separate `redis_batch` connection (`retry_after` 1000s by default, `REDIS_QUEUE_BATCH_RETRY_AFTER` env-overridable) so the invariant above holds for both groups without weakening retry semantics on the tight-timeout queues.

QUE-07 (Batch M1c): every frequent scheduled entry in `routes/console.php` now passes `withoutOverlapping()` an explicit short expiry (minutes) instead of relying on the framework's 24-hour default mutex — `outbox:publish` and `plot-reservation:expire-stale-draft-holds` at 5 minutes, `spine:watchdog` at 10 minutes, `documents:reconcile-storage-cleanup` (QUE-09, below) at 30 minutes. Without an explicit expiry, one ungraceful kill of a scheduled run (an OOM-killed process, `kill -9`, a host reboot mid-tick) would hold that job's overlap mutex — and therefore silence that job — for up to 24 hours, even though the run itself takes seconds. **Manual recovery** if a mutex is ever suspected stuck before its expiry naturally lapses: `php artisan schedule:clear-cache` clears every cached overlap mutex on the host, letting the next scheduler tick run immediately rather than waiting out the expiry. Run it from the host the scheduler runs on (per `docs/operations/dev-staging-environment.md`'s combined dev/staging cron-based scheduler), and re-check `spine:watchdog`'s output afterwards to confirm the pipeline actually resumed rather than merely that the mutex cleared.

QUE-09 (Batch M1c): `App\Platform\DocumentVault\Jobs\ReconcileDocumentStorageCleanupJob` — documented on its own class as "the recovery entry point for scheduler/worker supervision" — had no dispatcher anywhere in the application until this batch. `routes/console.php` now schedules a thin wrapper, `documents:reconcile-storage-cleanup`, hourly. That job dispatches its own recovery work onto the `media` queue (matching `ScanDocumentJob` and the rest of `App\Platform\DocumentVault\Jobs\*`); per QUE-01 (a separate, out-of-scope host-infra item) the `media` queue currently has no consumer running in beta, so this reconciliation sweep will queue but not drain until that worker exists. The scheduler wiring is correct regardless of that dependency and needs no further change once a `media` worker is running.

## 10. Combined dev/staging worker profile

On the Ubuntu 22.04 2/4 non-production host:

- staging runs one constrained Horizon deployment across `critical,urgent,notifications,default`;
- maximum normal worker processes: two total;
- development workers run with `--stop-when-empty` on demand;
- `imports,media,reports` use an on-demand staging batch worker;
- batch/import work must not run concurrently with critical UAT/payment testing unless resource headroom is verified;
- queues, prefixes, locks, and Horizon names are environment-specific;
- production Horizon topology remains unchanged.
