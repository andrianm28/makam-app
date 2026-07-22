# Performance and Capacity Baseline — v0.4

## 1. Objective

Performance is verified through repeatable representative tests, not assumptions based on framework choice.

## 2. Source-mandated targets

- Grave fuzzy search: below 500 ms at 100,000 records.
- Import: 10,000 rows through queue with row-level errors.
- Signed deceased-document URL: maximum five minutes.
- Reminder and recurring billing: idempotent per window/cycle.

## 3. Provisional application targets

Pending approved traffic forecast, use these engineering guardrails:

| Operation | Target |
|---|---|
| Cached/public homepage server response p95 | <= 500 ms excluding external CDN/network |
| Booking wizard read/save p95 | <= 1,000 ms excluding large file upload |
| Critical domain write p95 | <= 1,500 ms |
| Grave search p95 at 100k | < 500 ms |
| Payment webhook HTTP acknowledgement | <= 2 seconds after durable store |
| Payment event-to-projection completion p95 | <= 30 seconds |
| Critical queue wait | <= 10 seconds |
| Urgent queue wait | <= 15 seconds |
| Admin table common filter p95 | <= 1,500 ms at representative data volume |

These are engineering targets, not contractual SLA until approved.

## 4. Initial load profiles

Test at least:

### Profile A — normal launch

- 50 concurrent virtual users;
- mixed homepage/directory/FAQ/wizard traffic;
- background notifications and routine jobs.

### Profile B — campaign/burst

- 150 concurrent virtual users;
- 3× normal read burst;
- concurrent wizard saves and marketplace browsing.

### Profile C — operational batch

- 10,000-row grave import;
- reminders/reconciliation scheduled;
- simultaneous critical payment webhook traffic;
- critical/urgent queue latency must remain within target.

### Profile D — concurrency invariants

- duplicate/replayed webhooks;
- concurrent quote acceptance/payment opening;
- duplicate renewal period;
- duplicate reminder/care cycle;
- specific-plot reservation race when enabled.

## 5. Dataset

Use synthetic but representative:

- 100,000 grave records with Indonesian names, spelling variations, blocks, dates, and source metadata;
- at least 100 cemeteries across five launch areas;
- vendor/product/order volume representing expected two-year horizon;
- realistic quote lines, status history, audit volume, and notification records.

Do not benchmark on empty/local SQLite data.

## 6. Search design

- normalized name column;
- `pg_trgm` extension;
- GIN or GiST index selected through benchmark;
- exact/reference and deterministic filters before/with fuzzy ranking;
- query service encapsulates threshold and ranking;
- `EXPLAIN (ANALYZE, BUFFERS)` evidence stored for baseline queries.

## 7. Capacity review triggers

Reassess architecture when any sustained condition occurs:

- database CPU/IO/connection saturation;
- critical queue cannot meet target after worker scaling;
- app tier requires independent scaling of a domain;
- search exceeds target after index/query optimization;
- object processing dominates web workers;
- operational team can support added infrastructure complexity.

Only then consider read replicas, dedicated search service, SQS/broker, Octane, or service extraction.

## 8. Performance release evidence

Record test commit, environment, runtime versions, dataset size, script, p50/p95/p99, throughput, error rate, resource graphs, bottlenecks, and accepted exceptions.

## 9. Interpretation for the 2/4 combined host

The combined development/staging server is intended for feature integration and limited UAT. It is not automatically accepted as production-capacity evidence.

- Normal staging smoke and limited concurrent UAT may run on the host.
- Profiles B–D and formal 100k/10k certification should use an isolated time window or temporary environment when they would interfere with development/staging.
- Load generation must run from a separate machine.
- Record host saturation and accepted limitations with every benchmark.
- Persistent memory above 80%, swap/OOM, or queue/database target failure triggers upgrade or environment split.
