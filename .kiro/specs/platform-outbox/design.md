# Design — Platform Transactional Outbox

## Why an outbox

A state change and its side effects must be all-or-nothing. Dispatching a queue job inline with a transaction loses the job if the process dies after commit, and publishes a phantom event if the transaction rolls back. The outbox makes the event part of the transaction, so the only remaining problem is delivery — which retry solves.

## Data

```text
outbox_events
  id (uuid), event_type, event_version, occurred_at
  aggregate_type, aggregate_id
  correlation_id
  payload            -- allowlisted, no restricted fields
  claimed_at, claimed_by, published_at, attempts, last_error
```

Index on `(published_at IS NULL, occurred_at)` for the publisher scan.

## Publish loop

```text
claim batch (atomic: UPDATE ... WHERE published_at IS NULL AND claimed_at IS NULL
             RETURNING, or SELECT ... FOR UPDATE SKIP LOCKED)
  -> dispatch to queue per event_type routing
  -> mark published
  -> on failure: release claim, increment attempts, bounded backoff
```

`SKIP LOCKED` gives concurrent publishers without double publication. A stale claim (crashed publisher) is reclaimed after a timeout — which is why consumers must be idempotent.

## Queues

Per `queue-and-outbox.md`: `critical`, `urgent`, `notifications`, `default`, and the on-demand batch queues `imports`, `media`, `reports`. Batch queues run on separate workers so they cannot starve the critical path. Staging Horizon pool is capped at two processes per ADR-0027.

## Consumer contract

Every consumer is idempotent on `event_id`. Consumers record processed event ids or use natural business uniqueness. At-least-once delivery is a guarantee, not an edge case.

## Observability

Unpublished depth, oldest unpublished age, publication lag p95, attempts distribution, stale-claim reclaims, per-queue wait time against the 10s/15s targets.
