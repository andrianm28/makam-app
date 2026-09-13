# Batch 2C — Release plot reservation on terminal order transitions (UNBUILT-01)

## Context

Derived from `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` §Batch 2C
(committed on `fix/phase0-critical-stopgaps`, not yet merged to trunk at the time
this derived plan was written; the finding text is reproduced here only to the
extent needed to execute this one unit of work, per AGENTS.md §Documentation).

**Finding (UNBUILT-01):** plot reservations anchored on `order_id` — created by
`ConvertDraftHoldToOrderReservation.php:106-116` with no `expires_at` — are never
released when the owning order lands on a terminal status. `CancelOrder`,
`RejectOrder`, and `ExpireOrder` are each a ~15-line pass-through straight to
`RecordOrderStatusChange`, with no reservation-release logic anywhere in that
path. A cancelled, rejected, or expired order therefore leaves its plot
permanently claimed (`plot_state = reserved`), with no further lifecycle action
in the codebase able to free it.

## Fix point

Inside `RecordOrderStatusChange::record()`'s `Audit::wrap()` mutation closure
(`app/Domain/OrderWorkflow/Actions/RecordOrderStatusChange.php`), immediately
after `$current->applyStatus($event)` and before `emitStatusChanged()`: when the
destination status `$to` is one of `OrderStatus::DIBATALKAN`, `DITOLAK`, or
`KEDALUWARSA` (the three terminal, non-completed states per
`OrderTransition::ALLOWED` — `SELESAI` is also terminal but is the completed
path and must keep its plot claim), look up
`PlotReservation::activeForOrder($current)` and, if one exists, call
`ReleasePlotReservation`.

- `PlotReservation::activeForOrder(Order $order): ?self` already exists
  (`app/Domain/PlotReservation/Models/PlotReservation.php:177-186`) and is
  already used in production by `ReservePlot.php:167` — not recreated.
- Lock ordering follows the existing precedent documented in
  `ReservePlot.php`'s class doc block ("LOCK THE ORDER ROW FIRST"): `$current`
  (the `Order`) is already locked with `lockForUpdate()` earlier in `record()`;
  `ReleasePlotReservation` then locks the `GravePlot`/reservation chain second,
  inside its own `DB::transaction()`.
- `ReleasePlotReservation` deliberately uses `Audit::record()` inside its own
  `DB::transaction()`, not `Audit::wrap()` (its own doc block: the plot-state
  divergence reason is only knowable once the plot row is locked, while
  `Audit::wrap()` fixes `$reason` at call time). Calling it from inside
  `RecordOrderStatusChange`'s own `Audit::wrap()` nests that
  `DB::transaction()` inside another transaction — Laravel treats this as a
  savepoint, which is safe. This is documented explicitly in a code comment at
  the call site.

## Test plan

A Feature test (real PostgreSQL, per AGENTS.md §Testing / memory
`feedback_verify_against_real_db_not_sqlite`) that:

1. Creates a grave plot, reserves it via `ReservePlot`, and simulates the
   order-anchored conversion path so the active reservation for the order has
   `order_id` set and no `expires_at` (mirroring
   `ConvertDraftHoldToOrderReservation`'s output shape).
2. Cancels the order (`CancelOrder`) and asserts the `plot_reservations` head
   row for that plot is now `RELEASED` and `grave_plots.plot_state` is back to
   `AVAILABLE`.
3. Repeats for `RejectOrder` (`DITOLAK`, with a required reason) and
   `ExpireOrder` (`KEDALUWARSA`) on fresh orders/plots.
4. Confirms the divergence path is respected: when the plot's `plot_state` has
   been overridden away from `RESERVED` (e.g. admin marked it `OCCUPIED`)
   before the order transitions, the reservation chain still closes to
   `RELEASED` but `plot_state` is left untouched — same
   `reasonWithDivergence()` behavior `ReleasePlotReservation` already has for
   its other callers.
5. Confirms an order transitioning to `SELESAI` does NOT release its active
   reservation (negative case — the completed path must keep its plot claim).
6. Confirms an order with no active reservation transitions to a terminal
   status without error (no reservation to release; must not throw).

## Verification

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress`
- `bash ci/verify-docs.sh`
- Full test suite run inside Docker against real `postgres:18` + `redis:8.2-alpine`
  containers (prefix `b2c-`), per `project_worktree_test_env` /
  `feedback_verify_against_real_db_not_sqlite` memory notes — host PHP (8.3) is
  too old for this repo's PHP 8.5 target.

## Review flag

This touches order lifecycle / plot-reservation financial-adjacent state.
Per AGENTS.md §Infrastructure-agent execution, human security/financial review
is required before merge.
