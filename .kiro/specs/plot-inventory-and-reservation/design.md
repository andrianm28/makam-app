# Design — Plot Inventory and Reservation

## Data

`blocks`, `plot_units`, `plot_status_events`, `plot_source_syncs`, `plot_reservations`, `reservation_events`.

## Locking

Use transactional unique active-reservation constraint plus row/advisory lock or authoritative external reservation API. All retries use idempotency key.

## Expiry

Scheduler marks expired reservations exactly once and emits release event. Checkout creation revalidates reservation.

## Monitoring

Source age, conflict rate, lock latency, expiry count, payment-after-expiry exception count.
