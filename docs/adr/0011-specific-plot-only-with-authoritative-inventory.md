# ADR-0011: Specific Plot Booking Only with Authoritative Inventory

## Status
Accepted

## Decision
Specific plot selection/reservation is enabled only when the operator provides authoritative identity/status, freshness evidence, and atomic locking.

## Consequences
Package/class confirmation remains default. Stale or degraded inventory automatically disables new reservations.
