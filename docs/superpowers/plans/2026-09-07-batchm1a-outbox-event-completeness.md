# Batch M1a — outbox event completeness for three settlement actions

07 Sep 2026. Phase 3 audit remediation, Batch M1a. Fixes QUE-02, QUE-03,
QUE-10 — three Medium findings, all the same shape: a settlement/assignment
action writes state and an audit row but never records the outbox event the
program's own contracts require for that transition.

Source of truth for scope: this batch's own dispatch prompt, quoting the raw
audit findings directly (`docs/superpowers/plans/2026-09-06-remediasi-audit-
makam.md` only lists Phase 3's batch themes, not per-item detail).

## QUE-02 — MarkMarketplaceOrderPaid never emits an outbox event

`app/Domain/Marketplace/Actions/MarkMarketplaceOrderPaid.php` settles
`payment_state -> DIBAYAR`, releases the vendor payable, and writes
`MarketplaceAuditActions::ORDER_PAYMENT_STATE_CHANGED` — all inside one
`DB::transaction()` — but records no outbox row, even though
`docs/architecture/queue-and-outbox.md` §6 lists `payment.received` among the
events requiring one for this kind of transition.

Fix: add a NEW catalogued event `marketplace_order.paid.v1`
(`aggregateType: marketplace_order`), emitted from inside the same
transaction, right after the `payment_state` write (mirroring where
`renewal.paid_online.v1` sits relative to `Renewal::update()` in
`MarkRenewalPaidOnline`). Do NOT reuse `payment.received.v1` — its catalogued
payload carries an `OrderInvoice` reference the marketplace domain has no
analogue for (`MarketplaceOrder` has no invoice model).

Register the event:
- `docs/contracts/event-catalog.md` — new row, Producer `Marketplace`, Main
  consumers `Notification`, Notes explaining it is the real customer-order
  payment-state transition, distinct from `payment.received.v1`.
- `app/Platform/Outbox/OutboxQueueRouter.php` — route to `Critical`, quoting
  the same §2 "payment webhook processing" text `payment.received.v1` is
  routed under (`MarkMarketplaceOrderPaid` is reached only from
  `ApplyPaymentSettlement::settleMarketplace()`, a payment webhook path).

Payload: references only — `marketplace_order_id`, `vendor_id`. No amount (an
integer amount is not itself restricted, but the existing `renewal.paid_
online.v1`/`care.cycle_created.v1` precedent for THIS specific field pattern
keeps amounts out of these particular payloads; nothing in this batch's scope
needs the amount downstream). Idempotency key:
`marketplace_order_paid:{order_id}`.

Explicitly OUT of scope (per the dispatch brief): wiring
`ProvisionalAggregateNotificationSubjectSource`'s `marketplace_order` arm and
a notification-matrix template row — that is Batch 2E's job. This batch only
emits the event correctly; nothing consumes it yet, and that is fine.

## QUE-03 — offline renewal settlement paths never emit an outbox event

Two producers write `renewals.status = DIBAYAR` for the offline/admin path
without ever recording `renewal.marked_external.v1` — a name already
catalogued (`event-catalog.md`: "Renewal | Billing guard | Prevents duplicate
period") but with NO current producer:

- `app/Domain/Renewal/Actions/MarkExternalRenewal.php` — creates the
  `renewals` row (source = EXTERNAL) plus a `RenewalExternalMarking` row,
  inside `Audit::wrap()`'s mutation closure.
- `app/Domain/Renewal/Actions/MarkRenewalPaidExternally.php` — transitions an
  existing online-opened renewal to DIBAYAR plus a `RenewalExternalMarking`
  row, inside `Audit::wrap()`'s mutation closure.

Fix: add `Outbox::record()` for `renewal.marked_external.v1`
(`aggregateType: renewal`) inside each action's existing mutation closure,
right after the row that reaches DIBAYAR is written/created. Payload:
`renewal_id`, `grave_record_id` — mirrors `MarkRenewalPaidOnline`'s own
`renewal.paid_online.v1` payload shape (minus `paid_source_ref`, which has no
analogue on the offline path — there is no provider transaction). Idempotency
key: `renewal_marked_external:{renewal_id}`.

Routing: `renewal.paid_online.v1` itself is NOT in `OutboxQueueRouter::ROUTES`
today (falls back to `default`) and there is no §2 text tying an
admin-triggered offline settlement to a specific queue. Per that router's own
documented policy ("do not add a case-by-case default guess"), this batch
leaves `renewal.marked_external.v1` unrouted (defaults to `default`) —
consistent with its online sibling, not a gap this batch introduces.

Notification wiring (the part that makes the event actually notify someone):
`ProvisionalAggregateNotificationSubjectSource` already has a `renewal` arm
(added 25 Aug 2026) — no code change needed there. What's missing is a
`notification_templates` row whose `outbox_event_name` = `renewal.marked_
external.v1`. `RecipientResolver::resolve()` and `DispatchNotification`'s
`matrixSource->forEvent()` both key recipient facts off the row's own
`event_name`, which must exactly match a label in
`docs/contracts/notification-matrix.md`'s Event column — and that column has
only ONE relevant row, "Renewal paid/verified" (already claimed, unique, by
the online path's template row). Reusing that label for a second template row
is impossible (`notification_templates.event_name` is UNIQUE), so this batch
adds a new matrix row, `Renewal paid/verified (external)`, with the IDENTICAL
recipient facts as the existing row (same real-world event, offline path) —
not new policy, just a new label to hang a second template row on. A new
migration seeds the matching `notification_templates` +
`notification_template_versions` rows, following
`2026_08_09_100020_seed_notification_templates_from_matrix.php`'s own
insert shape (guarded for idempotent rerun, immutable version-1 body).

Test: new `tests/Feature/Domain/Renewal/RenewalMarkedExternalNotificationTest.php`,
modeled directly on `RenewalPaidOnlineNotificationTest.php` — proves the
`MarkExternalRenewal` path records the outbox row, that
`ConsumeOutboxNotificationJob` resolves a real `cemetery_operator` recipient
and writes an `in_app_notifications` row, and the AC8 duplicate-delivery
no-op.

## QUE-10 — AssignWorkOrder never emits an outbox event

`app/Domain/VendorFulfillment/Actions/AssignWorkOrder.php` writes
`WORK_ORDER_ASSIGNED` via `Audit::wrap()` but no outbox row, though
`vendor.order_assigned` is on `queue-and-outbox.md` §6's mandatory list.

Fix: add `Outbox::record()` for `vendor.order_assigned.v1` inside the
existing `Audit::wrap()` mutation closure, right after `$workOrder->update()`.
`aggregateType: work_order`. Payload (references only):
`work_order_id`, `vendor_id`, `care_plan_id`, `subscription_cycle_id` (the
"care plan/cycle id" the brief asks for — `WorkOrder` carries both FKs,
`subscription_cycle_id` nullable for a directly-created work order).
Idempotency key: `vendor_order_assigned:{work_order_id}`, exactly as the
dispatch brief specifies.

Catalog: `vendor.order_assigned.v1` is not yet in `event-catalog.md` (only
`vendor.work_completed.v1` / `vendor.evidence_uploaded.v1` exist for this
domain) — add it, Producer `VendorFulfillment`, Main consumers
`Case/customer/notification`.

Routing: no §2 text ties vendor-assignment to a specific queue either;
left unrouted (defaults to `default`), same reasoning as QUE-03.

## Shared conventions followed (not invented)

- `Outbox::record()` is called from INSIDE the same transaction/closure that
  performs the state mutation (`Audit::wrap()`'s closure, or the existing
  `DB::transaction()` closure for `MarkMarketplaceOrderPaid`) — never after.
- `classification: OutboxClassification::Internal` for all three — same as
  every sibling settlement/assignment event in these domains
  (`care.work_order_created.v1`, `renewal.paid_online.v1`,
  `vendor_order.decided.v1`).
- Payloads are references only (ids), per AC7/AC14 — no amounts, no PII, no
  provider payload values smuggled in where a sibling event's payload
  already sets a narrower precedent.
- Idempotency keys are `{event_shorthand}:{aggregate_id}` for the two
  single-emission events (QUE-02/QUE-10) and `renewal_marked_external:
  {renewal_id}` for QUE-03 (produced by two different call sites, but never
  for the same renewal id twice).

## Verification plan

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- `bash ci/verify-docs.sh`
- New/targeted feature tests against real PostgreSQL in Docker:
  - `RenewalMarkedExternalNotificationTest` (new)
  - Existing `RenewalPaidOnlineNotificationTest` (regression guard — must
    stay green; proves the new matrix row doesn't collide with the old one)
  - Any existing tests for `MarkMarketplaceOrderPaid`, `MarkExternalRenewal`,
    `MarkRenewalPaidExternally`, `AssignWorkOrder` (regression)
  - `OutboxQueueRouter`/event-catalog consistency tests if any exist
- All run inside Docker against Postgres 18 + Redis 8.2, container prefix
  `m1a-`, per this batch's dispatch instructions (host PHP is 8.3, too old).
