# Design — Cemetery Operator Dashboard

## Panel design

Dedicated Filament panel using shared identity and cemetery assignment scopes.

## Core views

- Pending availability requests.
- Assigned cemetery availability.
- Orders by status.
- Decision history.

## Non-blocking workflow

Operator request has configurable response target. When target expires, admin receives escalation, but order remains actionable. No state transition requires operator actor specifically; manual admin evidence is accepted.

## Metrics

- Operator login/adoption.
- Median response time.
- No-response rate.
- Admin fallback rate.
- Cross-cemetery authorization denials.
