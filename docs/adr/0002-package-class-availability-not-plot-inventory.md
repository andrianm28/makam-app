# ADR-0002: Package/Class Availability, Not Plot Inventory

## Status

**Superseded by ADR-0009 and ADR-0011.**

## Original decision

The initial baseline modeled availability only at package/class level because RKS explicitly excluded real-time plot inventory from the mandatory path.

## Reason for supersession

Benchmark validation showed that some private operators maintain plot numbers, maps, and certificates. v0.2 retains package/class as the safe default but supports optional plot inventory only for authoritative sources with atomic reservation.
