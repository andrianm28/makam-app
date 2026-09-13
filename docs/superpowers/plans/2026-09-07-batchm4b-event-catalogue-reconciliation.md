# Plan — Phase 3 Batch M4b: event-catalogue reconciliation

Date: 07 Sep 2026
Branch: `fix/batchm4b-event-catalogue-reconciliation`
Findings closed: API-05, CONTRACT-01 (duplicate of API-05), CONTRACT-02, API-06,
CONTRACT-03 (duplicate of API-06), CONTRACT-05.

## Context

Six findings from the audit's traceability work all point at the same two
documents (`docs/contracts/event-catalog.md`, `docs/contracts/outbox-event-contract.md`)
being out of sync with what `app/` actually emits. This batch reconciles them
without inventing new behaviour except where a review has already judged the
behaviour itself to be the bug (CONTRACT-05).

PR #252 (`fix/batchm1a-outbox-event-completeness`, not yet merged as of this
plan) adds `marketplace_order.paid.v1`, a `renewal.marked_external.v1`
producer pair, and `vendor.order_assigned.v1`. Verified directly: none of
those three producers exist yet on this branch's base (`AssignWorkOrder`,
`MarkMarketplaceOrderPaid`, `MarkExternalRenewal`, `MarkRenewalPaidExternally`
all still have zero `Outbox::record()` calls), so this batch's "missing from
catalogue" and "no producer" scans reflect the current, pre-#252 state
honestly. `renewal.marked_external.v1` is listed under CONTRACT-02 below as
having no current producer — that will close on its own when #252 merges; the
row is not deleted, only flagged not-yet-produced.

## API-05 / CONTRACT-01 — 7 emitted events missing from the catalogue

Grepped every `eventName:` argument to `Outbox::record(` across `app/` and
diffed against `event-catalog.md`'s event column. Seven names are emitted in
production code with no catalogue row:

| Event | Producer | Why it's missing |
|---|---|---|
| `booking.draft_started.v1` | `Booking\Actions\StartBookingDraft` | Disclosed gap, finding N-17 (sprint-plan.md) |
| `booking.draft_step_saved.v1` | `Booking\Actions\SaveBookingDraftStep` | Disclosed gap, finding N-17 |
| `feature_gate.state_changed.v1` | `FeatureGate\GateActivationRecorder` | Disclosed gap, finding N-12 |
| `vendor_order.complaint_filed.v1` | `Marketplace\Actions\UpdateVendorOrderStatus` | Never catalogued |
| `care.complaint_investigating.v1` | `VendorFulfillment\Actions\StartInvestigatingComplaint` | Never catalogued |
| `care.complaint_resolved.v1` | `VendorFulfillment\Actions\ResolveComplaint` | Never catalogued |
| `care.complaint_dismissed.v1` | `VendorFulfillment\Actions\DismissComplaint` | Never catalogued |

N-17 and N-12 already recorded these two gaps as deliberate, disclosed
decisions (a producer may emit an event the catalogue doesn't yet have,
per `platform-outbox` AC3 — it may not itself invent a *catalogue* entry).
This batch is the "whoever owns event-catalog.md next" both findings asked
for: add the rows.

Also corrected: `care.work_order_created.v1`'s note said "one per paid cycle"
but the event has two real producers — `VendorFulfillment\Actions\CreateWorkOrder`
and `VendorFulfillment\Actions\CreateWorkOrderFromCycle` (verified: both call
`Outbox::record(eventName: 'care.work_order_created.v1', ...)`). The note is
corrected to name both without asserting a cardinality the code doesn't
enforce.

Action: add 7 rows to `event-catalog.md`, correct the `care.work_order_created.v1`
note, add a dated reconciliation note at the bottom matching the file's
existing convention.

## CONTRACT-02 — 10 catalogued events with no producer

Grepped `event-catalog.md`'s full event column (42 entries) against every
`eventName:` literal in `app/`. Excluding the three `plot.reservation_*`
names already marked superseded by the 16 Aug 2026 note, ten rows have no
producer anywhere in `app/`:

| Event | Owning spec (`.kiro/specs/`) |
|---|---|
| `booking.draft_submitted.v2` | `public-booking-wizard` (Step 9 submission; wizard stops at Step 5 today) |
| `funeral_case.manager_assigned.v1` | `funeral-case-management` |
| `funeral_case.task_overdue.v1` | `funeral-case-management` |
| `availability.requested.v1` | `cemetery-directory-and-availability` |
| `availability.confirmed.v2` | `cemetery-directory-and-availability` |
| `cemetery.capability_changed.v1` | `cemetery-directory-and-availability` |
| `grave.import_completed.v1` | `renewal-and-grave-registry` |
| `grave.reminder_sent.v1` | `renewal-and-grave-registry` |
| `vendor.work_completed.v1` | `grave-care-fulfillment` |
| `renewal.marked_external.v1` | `renewal-and-grave-registry` (producer pending, PR #252) |

This is documentation-only: add a Status column to `event-catalog.md`'s
table marking each row `not yet produced — see <spec>`, following the
convention the existing 16 Aug 2026 superseded-names note already
established (kept as history/roadmap, not evidence of an active contract).
No producer code is invented.

## API-06 / CONTRACT-03 — null `actor.type`/`actor.id`

Verified: `Outbox::record()` (`app/Platform/Outbox/Outbox.php`) has no
`actorType`/`actorId` parameters and the `outbox_events` table
(`2026_07_26_140000_create_outbox_events_table.php`) has no actor columns —
this was a **deliberate** decision recorded in that migration's own doc
block and in finding N-11 (sprint-plan.md): "no `actor_type`/`actor_id`
columns — `queue-and-outbox.md` §5's cited 'minimum' schema doesn't list
them either, so the published envelope's `actor` key is emitted with null
values rather than fabricating storage the cited schema doesn't have."

This is a decision point, not a free code change (adding those columns is a
schema/behaviour change under AGENTS.md §Infrastructure-agent execution —
human sign-off required). Per the batch brief, the fix here is the
doc-amendment route: bring `outbox-event-contract.md` and
`funeral-case-events.md` in line with what N-11 already decided, using the
same dated-amendment convention `payment-webhook.md` already carries (its
"Amendment — four states added 10 Aug 2026" section is the template).

**Alternative flagged for human review (not done here):** thread
`ActorContext` through `Outbox::record()` so `actor_type`/`actor_id` are
populated for real, with a migration adding the two columns. Available as a
future code change if a human decides the doc-amendment route isn't
sufficient — e.g. if a downstream consumer actually needs `actor` populated
rather than falling back to `audit_events`.

## CONTRACT-05 — `care.cycle_created.v1` fires on payment, not creation

Verified directly: `CareSubscription\Actions\GenerateCycle` writes the cycle
and its invoice and records a `CYCLE_GENERATED` **audit** event — it never
calls `Outbox::record()`. `CareSubscription\Actions\MarkCyclePaid` is the
actual (and only) producer of `care.cycle_created.v1`, on the cycle's
PAID transition. `docs/domain/traceability-matrix.md`'s CARE-SUB-02 entry
already recorded this exact discrepancy (v0.17-era correction) but the
contract itself — the event's live name — was never fixed.

**Decision: code fix, not a catalogue amendment.** Reasoning:

- Grepped every reference to the string `care.cycle_created` across `app/`
  and `tests/`: the only real call sites are `MarkCyclePaid`'s own
  `Outbox::record()` call and its own test assertion
  (`MarkCyclePaidTest::test_mark_paid_emits_outbox_event`). No listener,
  consumer, or second producer references the name.
- `outbox_events` held 0 rows as of the last verified query (finding N-12,
  8 Aug 2026) and no notification-matrix row is keyed on this event name
  (`grep -rn care.cycle_created app/Platform/Notification` returns nothing) —
  so there is no known already-published data whose `event_name` column
  would need a backfill, and no live consumer contract to break.
- A rename is strictly more honest than an amendment note that says "v1
  keeps the legacy name for historical reasons," since nothing external
  currently depends on the legacy name.

**Human-review flag:** if a production or staging `outbox_events` table
*does* hold rows with `event_name = 'care.cycle_created.v1'` (this plan did
not query makam-nonprod's live database — that requires `docker`/`psql`
access this worktree does not have), a backfill migration renaming those
rows' `event_name` to `care.cycle_paid.v1` is needed before/alongside this
deploy, and a human should confirm that before merging. Flagged in the PR
description; **not** run here (destructive migration → AGENTS.md
§Infrastructure-agent execution, human sign-off required).

Action:
- Rename `MarkCyclePaid`'s emission from `care.cycle_created.v1` to
  `care.cycle_paid.v1` (event name only; aggregate/idempotency key/payload
  shape unchanged).
- Update `MarkCyclePaidTest.php`'s assertion to match.
- Update `event-catalog.md:37`'s event column and note.

## Verification plan

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (bump memory limit if needed)
- `bash ci/verify-docs.sh`
- `vendor/bin/phpunit` (or targeted: `MarkCyclePaidTest`, outbox/event-catalog
  related suites) against real PostgreSQL 18 + Redis 8.2 in Docker, container
  prefix `m4b-`.

All run inside Docker per CLAUDE.md (host PHP is 8.3, too old). Report
BLOCKED/NOT TESTED explicitly for anything not actually executed.
