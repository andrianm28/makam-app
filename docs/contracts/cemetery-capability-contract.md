# Cemetery Capability Contract

## Purpose

Contract between Makam.co.id and an operator/data source describing supported modes.

## Example

```json
{
  "cemetery_id": "cem_123",
  "version": 3,
  "availability_mode": "SPECIFIC_PLOT",
  "booking_mode": "RESERVE_PLOT",
  "map_mode": "PLOT_MAP",
  "registry_mode": "AUTHORITATIVE",
  "certificate_mode": "MANUAL",
  "visitation_mode": "BOOKABLE",
  "source_system": "operator-core",
  "freshness_seconds": 300,
  "effective_at": "2026-08-01T00:00:00+07:00"
}
```

## Validation

Invalid combinations are rejected. Activation requires evidence and audit. Downgrade blocks new actions and creates operator-visible resolution tasks for active reservations/cases.
