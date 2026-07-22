# Plot Reservation Contract

## Commands

- `holdPlot(plotId, processId, ttl, idempotencyKey)`
- `confirmReservation(reservationId, quoteVersion, idempotencyKey)`
- `releaseReservation(reservationId, reason)`
- `expireReservation(reservationId)`

## Required response

Reservation ID, plot ID, status, acquired/expiry time, source version, and concurrency token.

## Errors

`PLOT_NOT_AVAILABLE`, `STALE_INVENTORY`, `RESERVATION_CONFLICT`, `CAPABILITY_DISABLED`, `EXPIRED`, `UNAUTHORIZED`.

## Invariants

Only one active hold/reservation per plot. Retried command returns prior outcome. Payment creation verifies reservation status and quote binding.
