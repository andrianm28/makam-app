# Tasks — Platform Transactional Outbox

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Define `outbox_events` with the versioned envelope from `outbox-event-contract.md`. _Requirements: 2_
- [x] Implement a write helper that inserts the event inside the caller's transaction. _Requirements: 1_
- [x] Implement atomic claim with `SKIP LOCKED` and stale-claim reclaim. _Requirements: 5_
- [x] Implement per-event-type queue routing from `queue-and-outbox.md`. _Requirements: 8_
- [x] Implement bounded backoff (permanent-failure escalation / observability alerting is AC12, out of scope per finding N-8). _Requirements: 6_
- [x] Implement a payload **denylist** rejecting restricted classifications at write time. _Requirements: 7_
- [ ] Propagate correlation id from request into event, job, provider call, and notification. **Partial** — request→event→queue-job is proven; provider/notification propagation is unbuilt (no such classes exist). _Requirements: 13_
- [ ] Configure Horizon supervisors and queue priorities; cap the staging pool at two processes. _Requirements: 8, 9_
- [ ] Isolate `imports`/`media`/`reports` onto on-demand workers. _Requirements: 8_
- [ ] Implement bounded replay from the outbox. _Requirements: 11_
- [x] Add tests: commit succeeds but dispatcher dies — event still published on recovery. _Requirements: 1, 6_
- [ ] Add tests: concurrent publishers never double-publish. **Partial** — `OutboxPublisherClaimTest` proves sequential non-double-claim; true cross-session `SKIP LOCKED` contention is not provable in this harness (see that test's doc block). _Requirements: 5_
- [ ] Add tests: duplicate delivery produces exactly one effect in each consumer. _Requirements: 4_
- [ ] Add tests: 10k-row import does not push critical queue wait past 10s. _Requirements: 9_
- [ ] Add tests: Horizon graceful termination does not lose or double-run a job. _Requirements: 6, 10_
- [ ] Reconcile with `event-catalog.md` so every catalogued event has a producer and at least one consumer. **Partial** — `booking.draft_submitted.v2` (and `booking.draft_started.v1`/`booking.draft_step_saved.v1`) are uncatalogued; see finding N-17. _Requirements: 3_

## Design system

This spec is infrastructure and has no customer-facing screen. Two indirect obligations, per [`docs/design/design-system.md`](../../../docs/design/design-system.md):

- Outbox lag is what makes a `pending` state honest. Consumers rendering `pending` (§6.7) depend on this lag staying inside the `performance-and-capacity.md` targets; if it does not, the UI is lying rather than merely slow.
- Operational dashboards (Horizon, outbox depth) are internal tooling and are **exempt** from the ten-state requirement, but any admin-panel surface built on top of them is not — it uses `<x-mk.table>` §3.5 and `<x-mk.badge>` §3.6 with tokens from [`resources/css/tokens.css`](../../../resources/css/tokens.css).

## Implementation status

The boxes above are the honest per-AC status as of 09 Aug 2026, verified
against the code, not a plan. The checked items are implemented and tested;
the unchecked ones are genuinely unbuilt (Sprint 6 scope per the execution
plan: Horizon supervisors, on-demand worker isolation, bounded replay, and
their load/graceful-termination tests), and the **Partial** items are
half-shipped with the remaining half named inline.

What Batch 3.4 (26 Jul 2026) shipped: the `outbox_events` table with
reconciled column names (N-11), `Outbox::record()` as the one write API,
`OutboxPublisher`'s `SELECT ... FOR UPDATE SKIP LOCKED` claim loop with
stale-claim reclaim and bounded-backoff retry, `OutboxQueueRouter`'s
event-name → queue routing table, and `PayloadClassification`, which is a
key-name **denylist** (not the allowlist this file's task 6 originally
described — see that class's doc block for why a denylist is correct for a
domain-owned payload shape). The required recovery test ("commit succeeds,
dispatcher dies, event still publishes on recovery") is real, against real
Postgres, in `tests/Feature/Outbox/OutboxRecoveryTest.php`.

The 09 Aug 2026 retrofit added the module's first and second real
producers — `StartBookingDraft` and `SaveBookingDraftStep` in
`app/Domain/Booking/Actions/**` — proving AC1 end to end against a real
domain mutation for the first time (`BookingDraftOutboxTest`,
`OutboxBookingDraftPublicationTest`), proving the outbox→queue-job half of
correlation propagation, and correcting the staleness in the pre-retrofit
status block this section replaces.
