# P3 — Plot Inventory + Reservation Module Design

**Date:** 16 Aug 2026
**Status:** Draft (approved by user 16 Aug 2026, pending written review)
**Scope:** The authoritative reservation half of the kiro availability decision ("availability/plot decisions through manual confirmation OR authoritative reservation" — at-need-booking requirement 5): plot inventory (blocks + bulk-generated plots with authoritative states) + an atomic reservation module + operator-driven booking integration. Foundation for P4 (memorial/QR/visitation) and P5 (pre-need, certificates).
**Depends on:** P1 (admin order management — the operator journey the reservation integrates with), P2 (admin data management patterns).

## 1. Goal

A cemetery's plot inventory becomes an authoritative, queryable record: blocks with capacity, bulk-generated plots with per-plot state (`available`/`reserved`/`occupied`/`maintenance`), and an atomic reservation module where an operator can claim a specific plot for an order — one active hold per plot, enforced by the plot-row lock + the plot's state aggregate (a partial unique index was tried and rejected — see §4.2), with an append-only audit/outbox trail. The P1 admin journey gains the reserve/confirm/release/expire actions; the public flow is unchanged (package/class default stays; specific-plot selection is a later phase).

## 2. In scope

1. **PlotInventory domain** (`app/Domain/PlotInventory/`): `CemeteryBlock` + `GravePlot` models, `PlotState` constants, `CreateCemeteryBlock` action (block + bulk plot generation, atomic), admin surfaces (`BlocksRelationManager` under CemeteryResource + standalone `GravePlotsResource` with state-override actions).
2. **PlotReservation domain** (`app/Domain/PlotReservation/`): `PlotReservation` append-only model (one active hold per plot enforced by the plot-row lock + plot state aggregate); `ReservePlot` (atomic claim, idempotent per order), `ConfirmPlotReservation`, `ReleasePlotReservation`, `ExpirePlotReservation`; new catalogued event `plot_reservation.state_changed.v1`.
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
- `CreateCemeteryBlock::__invoke(Cemetery $cemetery, string $code, string $name, int $capacity, int|string $actorReference, ?string $actorRole = 'admin', ?int $cemeteryPackageId = null, ?bool $isActive = true, AuditSource $auditSource = AuditSource::Panel, ?string $reason = null): CemeteryBlock` — one transaction: create the block, generate `capacity` plot rows with slots zero-padded `001..N` (all `available`), audit `CEMETERY_BLOCK_CREATED` + `GRAVE_PLOTS_GENERATED`. The `capacity` and plot count travel in the audit `reason` text, NOT metadata: the `MetadataAllowlist` has no `capacity`/`plot_count` key and is deliberately not extended for this module. `$isActive` (default `true`, matching the column default) carries the admin create form's `is_active` toggle into the block row — it is wired through, never a silent no-op. No outbox event (inventory is reference data; audit suffices this phase).
- Admin: `BlocksRelationManager` under CemeteryResource (create block + generate plots; the PackagesRelationManager pattern) + `GravePlotsResource` (filter by cemetery/block/state; actions: mark occupied/maintenance/available — each a state-change action with audit `GRAVE_PLOT_STATE_CHANGED`; no delete action). Every override requires recent re-authentication (`ReauthenticationGuard` — AGENTS.md's plot-override invariant) and the run path re-reads the record (`fresh()`) and refuses transitions whose CURRENT state is not in the target's from-set (`markAvailable` from maintenance/occupied only; `markOccupied` from available/reserved/maintenance; `markMaintenance` from available/reserved/occupied) — `->visible()` is render-time only, so the refusal is what protects a reserved plot from a wire-called 'Tandai Tersedia' (finding I2).

### 4.2 PlotReservation

- `plot_reservations`: uuid id, `plot_id` FK restrict, nullable `order_id` FK restrict, `reserved_by_ref`, `state` (`held`/`confirmed`/`released`/`expired` — `PlotReservationState` constants), `reason` nullable, `reserved_at`/`confirmed_at`/`released_at`/`expired_at` nullable, timestamps. **Append-only rows**: every transition inserts a new row. "One active hold per plot" is enforced by the **plot-row lock + `plot_state` aggregate**: every reservation action `lockForUpdate()`s the plot row FIRST, asserts `plot_state === available` under that lock, and flips it to `reserved` in the same transaction; release/expire flip it back to `available`. The plot's `plot_state` mirrors the latest active reservation. **Override-divergence rule (finding C1):** the release/expire flip to `available` happens ONLY while the locked plot's state is `reserved`. If the state under the lock is NOT `reserved` — an admin override ('Tandai Terisi' → occupied, 'Tandai Perawatan' → maintenance) landed behind the chain, or the state is corrupt — release/expire still close the chain (the released/expired row is appended; the order loses its active hold) but do NOT touch `plot_state`: the override is preserved, and the action's audit reason records 'plot state diverged from reserved (override preserved)'. **Rejected alternative (recorded):** the original design backstopped the invariant with a partial unique index `plot_reservations_active_hold` on `(plot_id) WHERE state = 'held'`; it was proven (on PostgreSQL 18 and SQLite) to never release, because rows are append-only and `state` never mutates — the ORIGINAL `held` row keeps its index entry forever, so a plot that was ever held could never be held again, defeating the release/expire → re-hold lifecycle. The index was dropped by `2026_08_16_100030_drop_plot_reservations_active_hold_index.php`.
- `ReservePlot::__invoke(GravePlot $plot, Order $order, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation`:
  1. Order already has an active reservation → return the incumbent (idempotent per order; `activeForOrder()`) — an outside-the-transaction fast path, NOT the correctness mechanism.
  2. Transaction: **`lockForUpdate` the ORDER row first and RE-RUN the incumbent check against the locked order** — the authoritative same-order guard (finding I1): two concurrent same-order claims on DIFFERENT plots both pass step 1 and would lock different plot rows, but under the order-row lock they serialize — the loser's re-check finds the winner's hold and returns it instead of inserting, so the second plot is never touched (different orders never contend on the row; no other reservation action takes an order lock, so the order → plot lock order cannot deadlock). Then `lockForUpdate` the plot → assert `plot_state === available` (else `PlotNotAvailableException::forPlot`) → insert `held` row → plot → `reserved` → audit `PLOT_RESERVATION_CREATED` (idempotent returns write no audit row — nothing was created) → outbox `plot_reservation.state_changed.v1` (idempotency key `plot_reservation:{$row->id}`).
- `ConfirmPlotReservation::__invoke(PlotReservation $reservation, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation` — `held`→`confirmed` (plot stays `reserved`); terminal/late-state refusals honest.
- `ReleasePlotReservation::__invoke(...)` — `held`/`confirmed`→`released`, plot → `available` while the locked state is `reserved` (otherwise the override-divergence rule above).
- `ExpirePlotReservation::__invoke(...)` — `held`→`expired`, plot → `available` while the locked state is `reserved` (otherwise the override-divergence rule above).
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
- Concurrent double-reserve: the plot-row `lockForUpdate()` serializes; the loser's re-read sees `plot_state = reserved` and is refused via `PlotNotAvailableException` (the former partial-unique-index backstop was removed — it never released on append-only rows, see §4.2).
- Terminal/late-state lifecycle refusals → honest notifications; outbox/audit failures roll back with the transition (AC4).

## 7. Testing

- Inventory: block create + bulk generation (slot count, unique slots, audit); guards (code, capacity, state); admin access matrix + state overrides; delete blocked. State-override wire-refusal regression (finding I2): a raw wire call (`mountTableAction` + `callMountedAction`) of 'Tandai Tersedia' against a RESERVED plot with fresh re-authentication is refused by the run-path `fresh()` re-read — no state change, no audit row, danger notification.
- Reservation: happy path (plot → reserved, HELD row, audit, outbox); **concurrent double-reserve** (two-connection test, driver-guarded for PG — the plot-row `lockForUpdate()` + `plot_state` aggregate; the partial-unique-index backstop was rejected because append-only rows never release the index, see §4.2); same-order-different-plots double claim (finding I1 regression, sequential form: the second plot stays `available` — the authoritative order-row lock inside the transaction prevents the true concurrent variant); occupied/maintenance refusal; order idempotency; lifecycle transitions + terminal refusals; **override-divergence regressions** (finding C1: release/expire on a chain whose plot was overridden to occupied/maintenance closes the chain, preserves `plot_state`, and notes 'plot state diverged from reserved (override preserved)' in the audit reason); audit/outbox rows per transition.
- Integration: reserve action options (cemetery + class filtering), reserve → infolist, lifecycle action visibility per state; full journey smoke (submit → operator journey → reserve → confirm → authorize payment — the P1 flow unchanged).
- Browser (dev, Playwright): blocks+plots admin smoke; operator reserves on a live order; infolist shows the reservation.

## 8. Delivery

One plan, lanes: L1 PlotInventory; L2 PlotReservation (the concurrency core); L3 booking integration. L2 actions are L3's dependency but plan-signature-pinned → all lanes parallel, merge order L1 → L2 → L3; deploy + browser UAT + whole-branch review per the established rhythm.
