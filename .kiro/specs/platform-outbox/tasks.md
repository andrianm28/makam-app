# Tasks — Platform Transactional Outbox

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Define `outbox_events` with the versioned envelope from `outbox-event-contract.md`. _Requirements: 2_
- [ ] Implement a write helper that inserts the event inside the caller's transaction. _Requirements: 1_
- [ ] Implement atomic claim with `SKIP LOCKED` and stale-claim reclaim. _Requirements: 5_
- [ ] Implement per-event-type queue routing from `queue-and-outbox.md`. _Requirements: 8_
- [ ] Implement bounded backoff and permanent-failure escalation. _Requirements: 6_
- [ ] Implement a payload allowlist rejecting restricted classifications at write time. _Requirements: 7_
- [ ] Propagate correlation id from request into event, job, provider call, and notification. _Requirements: 13_
- [ ] Configure Horizon supervisors and queue priorities; cap the staging pool at two processes. _Requirements: 8, 9_
- [ ] Isolate `imports`/`media`/`reports` onto on-demand workers. _Requirements: 8_
- [ ] Implement bounded replay from the outbox. _Requirements: 11_
- [ ] Add tests: commit succeeds but dispatcher dies — event still published on recovery. _Requirements: 1, 6_
- [ ] Add tests: concurrent publishers never double-publish. _Requirements: 5_
- [ ] Add tests: duplicate delivery produces exactly one effect in each consumer. _Requirements: 4_
- [ ] Add tests: 10k-row import does not push critical queue wait past 10s. _Requirements: 9_
- [ ] Add tests: Horizon graceful termination does not lose or double-run a job. _Requirements: 6, 10_
- [ ] Reconcile with `event-catalog.md` so every catalogued event has a producer and at least one consumer. _Requirements: 3_

## Design system

This spec is infrastructure and has no customer-facing screen. Two indirect obligations, per [`docs/design/design-system.md`](../../../docs/design/design-system.md):

- Outbox lag is what makes a `pending` state honest. Consumers rendering `pending` (§6.7) depend on this lag staying inside the `performance-and-capacity.md` targets; if it does not, the UI is lying rather than merely slow.
- Operational dashboards (Horizon, outbox depth) are internal tooling and are **exempt** from the ten-state requirement, but any admin-panel surface built on top of them is not — it uses `<x-mk.table>` §3.5 and `<x-mk.badge>` §3.6 with tokens from [`resources/css/tokens.css`](../../../resources/css/tokens.css).

## NOT TESTED

Nothing here is implemented. No Redis queue, no Horizon, no worker exists. Redis 8.2.7 is running in the `makam-nonprod` stack but had `dbsize=0` and no `requirepass` as of 25 Jul 2026 (auth is sprint task S2-T6). `outbox-event-contract.md` and `event-catalog.md` exist but have **not** been reconciled against these criteria event by event.
