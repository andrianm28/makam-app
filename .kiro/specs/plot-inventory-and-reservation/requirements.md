# Requirements — Plot Inventory and Reservation

**Status:** Optional/gated B05.

## Acceptance criteria

1. Specific plot feature is enabled only for an approved cemetery capability profile.
2. Plot has stable source identity, block/location attributes, source version, observed time, and status history.
3. Reservation acquisition is atomic and idempotent.
4. Only one active hold/reservation exists per plot.
5. Reservation has TTL, owner process, quote binding, and release reason.
6. Stale/degraded source stops new reservations.
7. Expired/released reservation cannot open payment.
8. Public map/list exposes only approved fields.
9. Admin override is privileged, reasoned, audited, and cannot silently resolve conflicts.

## Negative criteria

- No plot booking from imported/basic registry without authoritative contract.
- No direct purchase merely because a plot appears available.
