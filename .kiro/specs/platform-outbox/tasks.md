# Tasks — Platform Transactional Outbox

- [ ] Define `outbox_events` with the versioned envelope from `outbox-event-contract.md`.
- [ ] Implement a write helper that inserts the event inside the caller's transaction.
- [ ] Implement atomic claim with `SKIP LOCKED` and stale-claim reclaim.
- [ ] Implement per-event-type queue routing from `queue-and-outbox.md`.
- [ ] Implement bounded backoff and permanent-failure escalation.
- [ ] Implement a payload allowlist rejecting restricted classifications at write time.
- [ ] Propagate correlation id from request into event, job, provider call, and notification.
- [ ] Configure Horizon supervisors and queue priorities; cap the staging pool at two processes.
- [ ] Isolate `imports`/`media`/`reports` onto on-demand workers.
- [ ] Implement bounded replay from the outbox.
- [ ] Add tests: commit succeeds but dispatcher dies — event still published on recovery.
- [ ] Add tests: concurrent publishers never double-publish.
- [ ] Add tests: duplicate delivery produces exactly one effect in each consumer.
- [ ] Add tests: 10k-row import does not push critical queue wait past 10s.
- [ ] Add tests: Horizon graceful termination does not lose or double-run a job.
- [ ] Reconcile with `event-catalog.md` so every catalogued event has a producer and at least one consumer.

## Design system

This spec is infrastructure and has no customer-facing screen. Two indirect obligations, per [`docs/design/design-system.md`](../../../docs/design/design-system.md):

- Outbox lag is what makes a `pending` state honest. Consumers rendering `pending` (§6.7) depend on this lag staying inside the `performance-and-capacity.md` targets; if it does not, the UI is lying rather than merely slow.
- Operational dashboards (Horizon, outbox depth) are internal tooling and are **exempt** from the ten-state requirement, but any admin-panel surface built on top of them is not — it uses `<x-mk.table>` §3.5 and `<x-mk.badge>` §3.6 with tokens from [`resources/css/tokens.css`](../../../resources/css/tokens.css).

## NOT TESTED

Nothing here is implemented. No Redis queue, no Horizon, no worker exists. Redis 8.2.7 is running in the `makam-nonprod` stack but had `dbsize=0` and no `requirepass` as of 25 Jul 2026 (auth is sprint task S2-T6). `outbox-event-contract.md` and `event-catalog.md` exist but have **not** been reconciled against these criteria event by event.
