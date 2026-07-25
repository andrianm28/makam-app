# Design — Plot Inventory and Reservation

## Data

`blocks`, `plot_units`, `plot_status_events`, `plot_source_syncs`, `plot_reservations`, `reservation_events`.

**Table ownership (normative):** this spec OWNS all six. `cemetery-directory-and-availability` consumes a read projection of `plot_units` / `plot_status_events` and must not define or migrate them. Resolves `docs/planning/kiro-specs-analysis.md` §5.1b.

## Locking

Use transactional unique active-reservation constraint plus row/advisory lock or authoritative external reservation API. All retries use idempotency key.

## Expiry

Scheduler marks expired reservations exactly once and emits release event. Checkout creation revalidates reservation.

## Monitoring

Source age, conflict rate, lock latency, expiry count, payment-after-expiry exception count.
