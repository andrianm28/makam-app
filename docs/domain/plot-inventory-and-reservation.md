# Plot Inventory and Reservation

## Status

Optional benchmark-derived capability. It does not replace RKS package/class confirmation for locations without authoritative data.

## Plot identity

A plot uses a stable source key:

```text
cemetery_id + source_system + external_plot_id
```

Block/name/coordinates are attributes, not the sole identity.

## Plot status

```text
UNKNOWN
AVAILABLE
HELD
RESERVED
OCCUPIED
UNAVAILABLE
MAINTENANCE
RETIRED
```

## Reservation lifecycle

```text
REQUESTED -> HELD -> CONFIRMED
               -> EXPIRED
               -> RELEASED
               -> CANCELLED
```

## Invariants

- Atomic acquisition prevents double hold.
- Reservation has expiration and owner process.
- Status source and observed/synced time are stored.
- Stale inventory disables new plot reservation.
- A payment session cannot be created for an expired/released reservation.
- Direct purchase requires legal title/right and certificate rules beyond reservation.
