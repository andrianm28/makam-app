# Cemetery Capability Model

## Purpose

Avoid forcing one workflow on TPU/TPS and private operators with different data maturity.

## Capability fields

| Field | Values | Safe default |
|---|---|---|
| `availability_mode` | `INDICATIVE`, `PACKAGE_CLASS`, `SPECIFIC_PLOT` | `INDICATIVE` |
| `booking_mode` | `REQUEST_CONFIRMATION`, `RESERVE_PLOT`, `DIRECT_PURCHASE` | `REQUEST_CONFIRMATION` |
| `map_mode` | `LOCATION_ONLY`, `BLOCK_MAP`, `PLOT_MAP` | `LOCATION_ONLY` |
| `registry_mode` | `NONE`, `BASIC`, `AUTHORITATIVE` | `NONE` |
| `certificate_mode` | `NONE`, `MANUAL`, `PLATFORM_MANAGED` | `NONE` |
| `visitation_mode` | `NONE`, `INFORMATION_ONLY`, `BOOKABLE` | `NONE` |

## Valid combinations

- `SPECIFIC_PLOT` requires `registry_mode=AUTHORITATIVE`.
- `RESERVE_PLOT` requires atomic reservation contract and freshness SLO.
- `DIRECT_PURCHASE` additionally requires approved price, legal, payment, cancellation, and certificate model.
- `PLOT_MAP` does not automatically imply public plot details.
- Capability downgrade must immediately block new actions but preserve existing cases/reservations for controlled resolution.

## Activation evidence

- data owner and operator approval;
- source/system identifier;
- sync method and freshness target;
- sample reconciliation;
- authorization and privacy review;
- failure/fallback test;
- owner and rollback plan.
