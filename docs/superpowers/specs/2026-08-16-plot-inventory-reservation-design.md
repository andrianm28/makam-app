# P3 — Plot Inventory + Reservation Module Design

**Date:** 16 Aug 2026
**Status:** Draft (approved by user 16 Aug 2026, pending written review)
**Scope:** The authoritative reservation half of the kiro availability decision ("availability/plot decisions through manual confirmation OR authoritative reservation" — at-need-booking requirement 5): plot inventory (blocks + bulk-generated plots with authoritative states) + an atomic reservation module + operator-driven booking integration. Foundation for P4 (memorial/QR/visitation) and P5 (pre-need, certificates).
**Depends on:** P1 (admin order management — the operator journey the reservation integrates with), P2 (admin data management patterns).

## 1. Goal

A cemetery's plot inventory becomes an authoritative, queryable record: blocks with capacity, bulk-generated plots with per-plot state (`available`/`reserved`/`occupied`/`maintenance`), and an atomic reservation module where an operator can claim a specific plot for an order — one active hold per plot, enforced by row locks and a partial unique index, with an append-only audit/outbox trail. The P1 admin journey gains the reserve/confirm/release/expire actions; the public flow is unchanged (package/class default stays; specific-plot selection is a later phase).

## 2. In scope

1. **PlotInventory domain** (`app/Domain/PlotInventory/`): `CemeteryBlock` + `GravePlot` models, `PlotState` constants, `CreateCemeteryBlock` action (block + bulk plot generation, atomic), admin surfaces (`BlocksRelationManager` under CemeteryResource + standalone `GravePlotsResource` with state-override actions).
2. **PlotReservation domain** (`app/Domain/PlotReservation/`): `PlotReservation` append-only model with a partial unique index enforcing one active hold per plot; `ReservePlot` (atomic claim, idempotent per order), `ConfirmPlotReservation`, `ReleasePlotReservation`, `ExpirePlotReservation`; new catalogued event `plot_reservation.state_changed.v1`.
3. **Booking integration**: 'Reservasi Plot' + lifecycle header actions on `ViewBookingOrder` (operator-initiated), reservation section in the order infolist, plot options filtered by the order's cemetery and package class.
4. **Admin data surface**: blocks/plots CRUD + state overrides, audited, `MasterDataAdminAuthorizerContract`-gated.

## 3. Out of scope

- Buyer-side specific-plot picker in the wizard (later phase; MVP package/class default stays).
- Auto-expiry scheduler (expiry is operator-on-demand this phase).
- Occupancy lifecycle beyond the plot state (funeral execution, care subscriptions — P5).
- Memorial/QR/visitation linkage (P4) — the `grave_records.block` string seam is noted, not wired.
- Land-rights listing through generic marketplace code (AGENTS.md forbids; not touched).

## 4. Architecture

### 4.1 PlotInventory

- `cemetery_blocks`: uuid id, `cemetery_id` FK restrict, `code` (uppercase, unique per cemetery), `name`, `capacity` (unsigned int ≥ 1), `is_active`, timestamps. Model guards: code normalized/asserted uppercase + non-blank, capacity ≥ 1.
- `grave_plots`: uuid id, `block_id` FK restrict, `slot` (`001..N`, unique per block), `plot_state` (`PlotState` constants), nullable `cemetery_package_id` FK (class link), timestamps. `PlotState`: `AVAILABLE='available'`, `RESERVED='reserved'`, `OCCUPIED='occupied'`, `MAINTENANCE='maintenance'`. Delete blocked while reservations exist or state ≠ available (honest refusal).
- `CreateCemeteryBlock::__invoke(Cemetery $cemetery, string $code, string $name, int $capacity, int|string $actorReference, ?string $actorRole = 'admin', ?int $cemeteryPackageId = null, AuditSource $auditSource = AuditSource::Panel, ?string $reason = null): CemeteryBlock` — one transaction: create the block, generate `capacity` plot rows with slots zero-padded `001..N` (all `available`), audit `CEMETERY_BLOCK_CREATED` (metadata: capacity, plot count) + `GRAVE_PLOTS_GENERATED`. No outbox event (inventory is reference data; audit suffices this phase).
- Admin: `BlocksRelationManager` under CemeteryResource (create block + generate plots; the PackagesRelationManager pattern) + `GravePlotsResource` (filter by cemetery/block/state; actions: mark occupied/maintenance/available — each a state-change action with audit `GRAVE_PLOT_STATE_CHANGED`; no delete action).

### 4.2 PlotReservation

- `plot_reservations`: uuid id, `plot_id` FK restrict, nullable `order_id` FK restrict, `reserved_by_ref`, `state` (`held`/`confirmed`/`released`/`expired` — `PlotReservationState` constants), `reason` nullable, `reserved_at`/`confirmed_at`/`released_at`/`expired_at` nullable, timestamps. **Append-only rows**: every transition inserts a new row; the **partial unique index `plot_reservations_active_hold` on `(plot_id) WHERE state = 'held'`** (PG + SQLite) is the database backstop for "one active hold per plot". The plot's `plot_state` mirrors the latest active reservation.
- `ReservePlot::__invoke(GravePlot $plot, Order $order, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation`:
  1. Order already has an active reservation → return the incumbent (idempotent per order; `activeForOrder()`).
  2. Transaction: `lockForUpdate` the plot → assert `plot_state === available` (else `PlotNotAvailableException::forPlot`) → insert `held` row (partial-unique backstop; duplicate → `PlotReservationConflictException`, narrow classifier) → plot → `reserved` → audit `PLOT_RESERVATION_CREATED` → outbox `plot_reservation.state_changed.v1` (idempotency key `plot_reservation:{$row->id}`).
- `ConfirmPlotReservation::__invoke(PlotReservation $reservation, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation` — `held`→`confirmed` (plot stays `reserved`); terminal/late-state refusals honest.
- `ReleasePlotReservation::__invoke(...)` — `held`/`confirmed`→`released`, plot → `available`.
- `ExpirePlotReservation::__invoke(...)` — `held`→`expired`, plot → `available`.
- All transitions: audit + outbox (same event name), append-only row insert, plot state flip in one transaction.
- New catalogued event in `docs/contracts/event-catalog.md`: `plot_reservation.state_changed.v1` (payload: reservation id, plot id, from_state, to_state).

### 4.3 Booking integration

- `ViewBookingOrder` gains header actions:
  - **'Reservasi Plot'** — visible while the order is at `DIVERIFIKASI` or `MENUNGGU_KETERSEDIAAN` and has no active reservation; modal with an available-plot select (plots of `bookingDraft.cemetery_id`, filtered by `cemetery_package_id` class link when the draft has one, showing slot + block code); confirm → `ReservePlot(order, plot)`.
  - **'Konfirmasi Reservasi'** (held), **'Lepaskan Reservasi'** (held/confirmed), **'Kedaluwarsakan Reservasi'** (held) — per-edge visibility.
- Order infolist gains a 'Reservasi' section (plot slot/block/state + reservation state + timestamps).
- No public-flow or Order-schema change (the reservation carries `order_id`).

## 5. Data flow

Operator → order view → plot select (available, cemetery+class filtered) → `ReservePlot` (lock → assert → HELD row + plot flip + audit + outbox, one transaction) → infolist reflects → lifecycle actions append rows + flip plot state. The guard's condition 2 remains status-based (already satisfied); the reservation is the authoritative backing record.

## 6. Error handling

- `PlotNotAvailableException` → notification, no state change; selection filtered to available plots (no stale UI).
- Concurrent double-reserve: row lock serializes; the partial unique index backstops; `PlotReservationConflictException` narrow-classified from `QueryException` (the `OrderAlreadyPaidException` pattern).
- Terminal/late-state lifecycle refusals → honest notifications; outbox/audit failures roll back with the transition (AC4).

## 7. Testing

- Inventory: block create + bulk generation (slot count, unique slots, audit); guards (code, capacity, state); admin access matrix + state overrides; delete blocked.
- Reservation: happy path (plot → reserved, HELD row, audit, outbox); **concurrent double-reserve** (two-connection test, driver-guarded for PG; lock + unique backstop); occupied/maintenance refusal; order idempotency; lifecycle transitions + terminal refusals; audit/outbox rows per transition.
- Integration: reserve action options (cemetery + class filtering), reserve → infolist, lifecycle action visibility per state; full journey smoke (submit → operator journey → reserve → confirm → authorize payment — the P1 flow unchanged).
- Browser (dev, Playwright): blocks+plots admin smoke; operator reserves on a live order; infolist shows the reservation.

## 8. Delivery

One plan, lanes: L1 PlotInventory; L2 PlotReservation (the concurrency core); L3 booking integration. L2 actions are L3's dependency but plan-signature-pinned → all lanes parallel, merge order L1 → L2 → L3; deploy + browser UAT + whole-branch review per the established rhythm.
