# Design — Cemetery Directory and Availability

## Components

`CemeteryDirectory`, `CemeteryCapability`, package/class availability read model, optional `PlotInventory` adapter.

## Data

```text
cemeteries(..., facilities[] closed codes per facility-catalog.md,
           operator_name, operator_contact_phone?, operator_hours_text?,
           required_documents_override[]? codes per required-document-catalog.md)
cemetery_capability_profiles(version, modes, source, owner, effective_at, evidence)
cemetery_packages / cemetery_classes
availability_snapshots
blocks / plot_units / plot_status_events (optional, READ-ONLY projection)
```

**`cemeteries` columns (added 19 Sep 2026, AC13/AC15):** `facilities` is today a free-text JSON array (migration `2026_07_26_190000_create_cemeteries_table.php`); AC13 converges it on the closed list, mapping legacy labels once and keeping unmapped labels display-only. The three new optional columns back AC15; the confirmation snapshot that freezes them per order is owned by `booking-and-order-orchestration`.

**Table ownership (normative):** `blocks`, `plot_units`, and `plot_status_events` are **owned by `plot-inventory-and-reservation`**. This spec reads a projection of them and must not define or migrate them. Resolves `docs/planning/kiro-specs-analysis.md` §5.1b.

## Rendering

A capability resolver produces an allowlisted UI/API feature set. Domain Actions re-check capabilities server-side.

## Degraded mode

If plot freshness or sync fails, set reservation unavailable, alert owner, and retain package/class/manual request path where configured.

## Metrics

Directory latency, capability distribution, stale source count, reservation fallback rate, and unauthorized public-field attempts.
