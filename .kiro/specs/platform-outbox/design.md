# Design — Platform Transactional Outbox

## Why an outbox

A state change and its side effects must be all-or-nothing. Dispatching a queue job inline with a transaction loses the job if the process dies after commit, and publishes a phantom event if the transaction rolls back. The outbox makes the event part of the transaction, so the only remaining problem is delivery — which retry solves.

## Data

```text
outbox_events
  id (uuid), event_name, event_version, occurred_at
  aggregate_type, aggregate_id
  idempotency_key     -- nullable UNIQUE, for idempotent consumers (AC4)
  trace_id            -- from CorrelationContext at insert time
  payload             -- DENYLISTED (PayloadClassification), no restricted fields
  classification      -- PUBLIC|INTERNAL|CONFIDENTIAL|RESTRICTED, CHECK-constrained
  available_at        -- first eligible publish time; bounded backoff pushes it forward
  attempt_count       -- publish attempts so far
  locked_at           -- set by a claim; stale claims are reclaimed after a timeout
  dispatched_at       -- non-null once published, never cleared
  last_error
```

Column names were reconciled against `queue-and-outbox.md` §5 and
`outbox-event-contract.md` when the table was built — finding N-11 in
`docs/planning/sprint-plan.md` records the `event_type` → `event_name`,
`correlation_id` → `trace_id`, `claimed_at` → `locked_at`, `published_at`
→ `dispatched_at`, `attempts` → `attempt_count` renames and the removal of
`claimed_by`. `available_at`, `attempt_count`, and `idempotency_key` were
added beyond this file's original paraphrase to support the real claim
predicate and at-least-once consumers.

Indexes (as shipped by `2026_07_26_140000_create_outbox_events_table.php`):
- `(dispatched_at, occurred_at)` — the publisher scan
- `(dispatched_at, locked_at, available_at)` — the claim predicate's full filter
- `trace_id` — reconstruct a full flow from one identifier

## Publish loop

```text
claim batch (atomic: SELECT ... FOR UPDATE SKIP LOCKED on
             rows WHERE dispatched_at IS NULL
             AND (locked_at IS NULL OR locked_at < now - STALE_CLAIM_SECONDS)
             AND available_at <= now)
  -> dispatch to queue per event_name routing
  -> mark dispatched
  -> on failure: release lock, increment attempt_count, bounded backoff
```

`SKIP LOCKED` gives concurrent publishers without double publication. A stale claim (crashed publisher) is reclaimed after a timeout — which is why consumers must be idempotent.

## Queues

Per `queue-and-outbox.md`: `critical`, `urgent`, `notifications`, `default`, and the on-demand batch queues `imports`, `media`, `reports`. Batch queues run on separate workers so they cannot starve the critical path. Staging Horizon pool is capped at two processes per ADR-0027.

## Consumer contract

Every consumer is idempotent on `event_id`. Consumers record processed event ids or use natural business uniqueness. At-least-once delivery is a guarantee, not an edge case.

## Observability

Unpublished depth, oldest unpublished age, publication lag p95, attempts distribution, stale-claim reclaims, per-queue wait time against the 10s/15s targets.
