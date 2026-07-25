# Requirements — Plot Inventory and Reservation

**Status:** Optional/gated B05.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec (`AC6`, `AC8`, `AC9` in `tasks.md`) and in other documents still points at the same requirement.

1. THE SYSTEM SHALL enable the specific-plot feature only for an approved cemetery capability profile.
2. THE SYSTEM SHALL maintain for each plot a stable source identity, block/location attributes, source version, observed time, and status history.
3. WHEN a reservation is acquired THE SYSTEM SHALL perform the acquisition atomically and idempotently.
4. THE SYSTEM SHALL NOT allow more than one active hold/reservation to exist per plot.
5. THE SYSTEM SHALL record for each reservation a TTL, owner process, quote binding, and release reason.
6. WHILE the plot source is stale or degraded THE SYSTEM SHALL stop new reservations.
7. THE SYSTEM SHALL NOT allow an expired or released reservation to open payment.
8. THE SYSTEM SHALL expose only approved fields on the public map/list.
9. WHEN an admin override occurs THE SYSTEM SHALL require it to be privileged, reasoned, and audited. THE SYSTEM SHALL NOT let an override silently resolve conflicts.

## Negative criteria

- No plot booking from imported/basic registry without authoritative contract.
- No direct purchase merely because a plot appears available.
