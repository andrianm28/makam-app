# Plot Reservation Contract

Rewritten 07 Sep 2026 (CONTRACT-09 audit finding) against the actual shipped
`App\Domain\PlotReservation` module — `database/migrations/
2026_08_16_100020_create_plot_reservations_table.php` +
`2026_08_29_100000_add_booking_draft_hold_to_plot_reservations_table.php`,
`PlotReservationState`, and the `Actions/` directory — rather than the
generic command/error-code shape the previous version of this doc described.

## Storage shape

`plot_reservations` is **append-only**: one row per state transition
(`held` | `confirmed` | `released` | `expired` | `converted` —
`PlotReservationState::KNOWN_STATES`). Rows are never updated or deleted
(the model enforces this). "The current state of a reservation chain" means
the latest row for that `plot_id`, ordered `created_at desc, id desc` — there
is no separate "reservation" aggregate row that gets mutated in place, and
no `PlotReservationIsAppendOnlyException` bypass exists.

A row is anchored to **either** `order_id` (operator-initiated, via
`ReservePlot`) **or** `booking_draft_id` (customer picker, before an `Order`
exists, via `HoldPlotForDraft`) — construction discipline in the Actions, not
a database CHECK constraint. `expires_at` is set only on draft-anchored
`held` rows (an order-anchored hold has no TTL); it is nullable and read by
`PlotReservationExpiryScheduler`'s per-minute sweep, not by the confirm/hold
commands themselves.

There is no "source version" or "concurrency token" field on the row. "One
active hold per plot" is enforced by taking `GravePlot::query()->
lockForUpdate()` first and asserting the LOCKED plot's `plot_state`/latest
reservation row, not by a database partial-unique index (an earlier
`plot_reservations_active_hold` partial unique index was created and then
deliberately dropped — see that migration's own doc block — because
append-only rows made it permanently block re-reservation of a plot that
was ever held).

## Commands (the real Actions, not generic verbs)

- **`ReservePlot(GravePlot $plot, Order $order, actorReference, actorRole, ?reason, AuditSource)`**
  — operator-initiated hold, anchored to an existing `Order`. Idempotent per
  order: a duplicate call for the same order returns the incumbent
  (pre-check outside the transaction, re-checked under the order-row lock
  inside it) — there is no separate idempotency-key parameter; the
  order/draft row itself is the idempotency anchor.
- **`HoldPlotForDraft(GravePlot $plot, BookingDraft $draft, actorReference, ?ttlMinutes, ?reason, AuditSource)`**
  — the customer-picker equivalent, anchored to a `BookingDraft` before an
  `Order` exists. Idempotent per `(draft, plot)` pair the same way — a
  repeat request for the SAME plot returns the incumbent; a request for a
  DIFFERENT plot releases the old hold (via `ReleasePlotReservation`) and
  creates a new one under the same draft lock, so a draft can never hold two
  plots at once. `ttlMinutes` defaults from
  `config('plot-reservation.draft_hold_ttl_minutes')`.
- **`ConfirmPlotReservation(PlotReservation $reservation, actorReference, actorRole, ?reason, AuditSource)`**
  — `held` → `confirmed`. **Takes no `quote_id` and performs no
  quote-binding check at all** — see "Known gap" below.
- **`ReleasePlotReservation(PlotReservation $reservation, actorReference, actorRole, ?reason, AuditSource)`**
  — `held`/`confirmed` → `released`; returns the plot to `available`.
- **`ExpirePlotReservation(PlotReservation $reservation, actorReference, actorRole, ?reason, AuditSource)`**
  — `held` → `expired` (TTL sweep path); returns the plot to `available`.
- **`ConvertDraftHoldToOrderReservation(PlotReservation $draftHold, Order $order, AuditSource)`**
  — closes a draft-anchored `held` chain (`held` → `converted`) and opens a
  new order-anchored `held` row for the same plot, at `SubmitBookingDraft`
  time. Throws `DraftPlotHoldNoLongerValidException` if the draft hold is no
  longer the live head of its plot's chain (expired, already converted, or
  superseded) — the caller (`SubmitBookingDraft`) does not fall back to
  submitting without a reservation; the whole submission rolls back and the
  customer is routed back to Step 2.

Every command's idempotency comes from **re-deriving the incumbent under a
row lock** (order row, draft row, or plot row, depending on the command),
never from a caller-supplied idempotency key.

## Errors (the real exception classes)

- `PlotNotAvailableException` — `ReservePlot`/`HoldPlotForDraft` only, when
  the locked plot's `plot_state` is not `available`.
- `PlotReservationTransitionException` — any lifecycle hop
  (confirm/release/expire) attempted from a state that isn't the allowed
  from-state for that transition (e.g. confirming an already-`expired`
  chain).
- `DraftPlotHoldNoLongerValidException` — `ConvertDraftHoldToOrderReservation`
  only, when the draft hold is no longer the live head of its chain.
- `PlotReservationIsAppendOnlyException` — thrown by the model itself if
  anything ever calls `update()`/`delete()` on a `plot_reservations` row.

There are no `PLOT_NOT_AVAILABLE`/`STALE_INVENTORY`/`RESERVATION_CONFLICT`/
`CAPABILITY_DISABLED`/`EXPIRED`/`UNAUTHORIZED` string error codes anywhere in
this module — those were never implemented; callers catch the typed
exceptions above (or, for the capability gate, the platform's standard
gate-check flow, which is not part of this module).

## External/authoritative-registry fields — reserved, not integrated

`event-catalog.md`'s `plot.reservation_acquired.v1` row mentions an
"authoritative source version" — no such field exists on `plot_reservations`
today, and the module emits `plot_reservation.state_changed.v1` (not the
`plot.reservation_*` names in that catalog row; that naming mismatch is
tracked separately and is out of scope for this doc-only correction). Any
external cemetery-registry/authoritative-source integration is **reserved
for future integration** — no such adapter exists in `app/` yet, and no
field on this table should be read as evidence one does.

## Known gap — quote-binding on confirm (flagged, not fixed here)

`ConfirmPlotReservation` accepts no `quote_id` and performs no check that
the reservation being confirmed corresponds to any accepted quote. Nothing
in `app/Domain/PlotReservation` or its callers binds a confirm to a specific
`Quotation` version. If a quote-binding invariant ("payment/confirm must
match the quote the customer actually accepted") is meant to hold, it is
**not enforced anywhere today**. This is a genuine gap, not a documentation
error to paper over — per the audit finding's instruction, it is raised here
as a **new follow-up finding** rather than fixed in this doc-only batch;
whoever triages the audit backlog should open a dedicated finding for adding
`quote_id` (or an equivalent check) to `ConfirmPlotReservation`.
