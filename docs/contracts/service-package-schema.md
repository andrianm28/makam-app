# Service Package Schema

```yaml
package_id: pkg_standard_muslim
version: 4
cemetery_id: cem_123
name: Paket Pemakaman Standar
included_items:
  - service_code: grave_digging
    quantity: 1
    unit: service
    fulfillment_owner: cemetery_operator
optional_items:
  - service_code: hearse
    fulfillment_owner: vendor
excluded_items:
  - land_fee
service_area: jabodetabek
service_window: same_day
substitution_policy: customer_approval_required
cancellation_policy_id: cancel_v2
evidence_requirements:
  - completion_photo
```

Published versions are immutable. Quote references exact package and price versions.
