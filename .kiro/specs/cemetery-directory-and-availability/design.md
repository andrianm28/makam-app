# Design — Cemetery Directory and Availability

## Components

`CemeteryDirectory`, `CemeteryCapability`, package/class availability read model, optional `PlotInventory` adapter.

## Data

```text
cemeteries
cemetery_capability_profiles(version, modes, source, effective_at, evidence)
cemetery_packages / cemetery_classes
availability_snapshots
blocks / plot_units / plot_status_events (optional)
```

## Rendering

A capability resolver produces an allowlisted UI/API feature set. Domain Actions re-check capabilities server-side.

## Degraded mode

If plot freshness or sync fails, set reservation unavailable, alert owner, and retain package/class/manual request path where configured.

## Metrics

Directory latency, capability distribution, stale source count, reservation fallback rate, and unauthorized public-field attempts.
