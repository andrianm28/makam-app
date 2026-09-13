# Batch M3b — plot-reservation domain guards (DOM-03, DOM-08)

Phase 3 audit remediation, per `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`.
Branch: `fix/batchm3b-plot-reservation-domain-guards`, based directly on trunk
(`docs/design-system-and-planning` @ `60124930`).

## Relationship to PR #246 (`fix/batch2c-plot-reservation-release-on-terminal-transitions`)

PR #246 adds a call to `ReleasePlotReservation` inside
`RecordOrderStatusChange::record()`, fired only when an order's *status*
transitions to `DIBATALKAN`, `DITOLAK`, or `KEDALUWARSA`. Checked
`OrderTransition::ALLOWED`: `DIBAYAR => ['DIPROSES']` is the only edge out of
`DIBAYAR`, and none of the three terminal-non-completed statuses are reachable
from `DIBAYAR` or `DIPROSES` in the transition matrix. So the order that
PR #246's call path releases a reservation for can **never** be paid — the
DOM-08 "paid order" guard added here to `ReleasePlotReservation` cannot ever
fire on PR #246's call path. No behavioural overlap, no merge-conflict risk on
shared lines (PR #246 only touches `RecordOrderStatusChange.php`; this batch
does not touch that file at all).

DOM-03 (payment-guard plot-reservation validity) and DOM-08 (paid-order
release/expire guard) are both scoped to code PR #246 does not touch:
`GuardPaymentSession`/`GuardCondition` (payment guard) and
`ReleasePlotReservation`/`ExpirePlotReservation`/
`PlotReservationLifecycleActions` (admin lifecycle actions). No file in this
batch is edited by PR #246, and vice versa.

## DOM-03 — payment guard must check plot-reservation validity

**Problem**: `GuardPaymentSession::conditionTwo()` only checks
`Order::status` membership in `CONFIRMED_STATUSES`. An order whose plot
reservation was released/expired (e.g. by an operator, or — after this
batch — a paid-order override) keeps its confirmed status and can still pass
condition 2, letting a payment session open for a plot that is no longer
actually held.

**Fix**: in `conditionTwo()`, after the existing status check holds, if the
order has ANY `plot_reservations` history at all (`order_id` foreign key),
additionally require `PlotReservation::activeForOrder($order) !== null`, and
when that active hold carries an `expires_at`, require it has not passed.
Orders with NO reservation history keep the current status-only path
unchanged (package/class confirmations with no specific plot).

Files: `app/Platform/Payment/GuardPaymentSession.php` (`conditionTwo()`
private method, `CONFIRMED_STATUSES` unchanged). No change needed to
`GuardCondition.php` itself (the enum only names the six conditions; the
comment already mentions "an active `PlotReservation`" for condition 2).

Tests: add cases to `tests/Feature/Payment/GuardPaymentSessionUpstreamTest.php`
covering: (a) confirmed order with a RELEASED reservation as its only history
→ condition 2 denies; (b) confirmed order with an EXPIRED
(`expires_at` in the past) HELD reservation → condition 2 denies; (c)
confirmed order with an active, unexpired HELD/CONFIRMED reservation →
condition 2 still holds; (d) confirmed order with NO reservation history at
all (existing tests) → unaffected, still holds.

## DOM-08 — release/expire actions have no paid-order guard

**Problem**: `ReleasePlotReservation` and `ExpirePlotReservation` (the domain
Actions) and `PlotReservationLifecycleActions::release()/expire()` (the admin
header actions) have no check against the reservation's OWNING ORDER already
being paid (`DIBAYAR`/`DIPROSES`/`SELESAI`). An operator (or any future
scheduled sweep) can silently return a paid-for plot to available inventory.

**Fix, structural (closes it everywhere, including the Floor/Block Map page's
own release/expire wiring which is a second call site not named in the
finding but sharing the same domain Actions)**:

- `OrderStatus::isPaidOrLater(): bool` — new method, `true` for
  `DIBAYAR`/`DIPROSES`/`SELESAI` (the only reachable statuses once an order is
  `DIBAYAR`, per `OrderTransition::ALLOWED`).
- `ReleasePlotReservation`/`ExpirePlotReservation` gain a trailing optional
  `bool $overridePaidOrder = false` parameter. Both re-derive the current
  chain's `order_id` (already read as part of the existing state-assert) and,
  when it is non-null, look up the `Order` and check
  `isPaidOrLater()`. If paid and `$overridePaidOrder` is false, throw a new
  `PlotReservationOrderAlreadyPaidException`. If paid and override is true (or
  not paid at all), proceed — but the audit action written differs: a paid
  override writes a NEW, distinct audit-action constant
  (`PLOT_RESERVATION_RELEASED_PAID_ORDER_OVERRIDE` /
  `PLOT_RESERVATION_EXPIRED_PAID_ORDER_OVERRIDE`), never the plain
  `PLOT_RESERVATION_RELEASED`/`_EXPIRED` ones, so the audit trail can tell an
  ordinary pre-payment release apart from a paid-order override. Both new
  constants are added to `SensitiveActions::ACTIONS` (unlike the plain
  release/expire actions, deliberately NOT on that list per
  `PlotReservationAuditActions`'s own doc block) — `Audit::record()` then
  structurally refuses a blank reason for the override path.
- The order lookup is a plain, unlocked read (no `lockForUpdate()`): this is a
  defense-in-depth admin/operator guard, not a strict-consistency invariant,
  and locking the Order row here (after the Plot row is already locked) would
  invert the Order-then-Plot lock ordering `RecordOrderStatusChange`/
  `ReservePlot` document, risking a deadlock between the two call paths for no
  correctness benefit.
- The scheduled sweep (`PlotReservationExpiryScheduler::expireStaleDraftHolds()`)
  only ever expires DRAFT-anchored holds (`booking_draft_id` set,
  `order_id` always null on that chain) — confirmed by reading
  `PlotReservationExpireStaleDraftHoldsCommand`'s own doc block ("operator-
  on-demand only" for order-bound expiry). The new guard is a no-op on that
  path (`order_id === null` short-circuits before any Order lookup), so no
  behavioural change to the sweep.

**Fix, UI (`PlotReservationLifecycleActions`, the file named in the finding)**:

- `release()`/`expire()` gain an additional `->visible()` condition: hidden
  once `$order->status()->isPaidOrLater()` is true (on top of the existing
  reservation-state condition).
- New `releasePaidOrderOverride(Order $order, PlotReservation $reservation): Action`
  factory: visible only when the order IS paid-or-later AND the reservation
  is still in an active state; label "Lepaskan Reservasi Pesanan Berbayar";
  mandatory `reason` `Textarea` (`->required()`); `->authorize()` uses the
  same `CemeteryOrderActionGate::allows($order)` the other three actions use;
  the `->action()` closure re-checks that gate, then calls
  `ReauthenticationGuard::assertFresh()` (copying
  `RecordExternalRenewalPaymentAction`'s redirect-to-challenge shape, reusing
  the existing single `PasswordReauthentication::ROUTE_NAME` — the same cross-
  panel reuse `BasePlotFloorMapPage::requireFreshAuthentication()` already
  relies on for the Operator panel, since only the Admin panel registers that
  page), then calls `ReleasePlotReservation` with `overridePaidOrder: true`
  and the supplied reason.
- Wired into both call sites (`ViewBookingOrder.php`, `ViewCemeteryOrder.php`)
  alongside the existing three actions.
- `BasePlotFloorMapPage::runReservationAction()` (the second release/expire
  call site) gets one added `catch` for the new exception with a clear
  Indonesian notification, instead of falling into the generic
  `catch (Throwable)` "terjadi kesalahan" message — this page offers no
  override UI for a paid order (out of scope: the finding's fix only calls
  for the override on the header actions), so a paid order's reservation
  simply cannot be released/expired from the Floor/Block Map, with a message
  that says why.

Tests:
- `tests/Feature/Domain/PlotReservation/PlotReservationLifecycleTest.php`:
  release/expire throw `PlotReservationOrderAlreadyPaidException` for a
  DIBAYAR-or-later order's reservation when `overridePaidOrder` is false
  (default); succeed and write the `_PAID_ORDER_OVERRIDE` audit action when
  true; existing tests (pre-payment orders) unaffected.
- `tests/Feature/Filament/PlotReservationLifecycleCemeteryOperatorTest.php`
  or a new sibling test: `release()`/`expire()` are not visible once the
  order is paid-or-later; `releasePaidOrderOverride()` is visible only then,
  and blank reason / stale re-authentication are refused.

## Human review required

Financial-adjacent per `AGENTS.md` §Infrastructure-agent execution: this
batch touches payment-guard evaluation (DOM-03) and paid-order plot-
reservation lifecycle (DOM-08). Flagged explicitly in the PR description for
mandatory human review before merge.

## Verification plan

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (memory limit bumped if needed)
- `bash ci/verify-docs.sh`
- Full PHPUnit run inside Docker against real PostgreSQL + Redis
  (`m3b-`-prefixed disposable containers), not SQLite — per this session's
  `feedback_verify_against_real_db_not_sqlite` note.
