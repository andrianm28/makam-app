# Service Package and Bundle Model

## Structure

```text
ServicePackage
├── included items
├── optional items
├── excluded items
├── quantities and units
├── service area
├── fulfillment owner
├── service window
├── substitution policy
├── cancellation policy
├── evidence requirement
└── versioned price snapshot
```

## Rules

- A package version is immutable once quoted.
- Included items cannot be removed without explicit new quote/acceptance.
- Optional selections become quote lines.
- Substitution requires equivalent-or-better rule or customer approval.
- One item may be internally fulfilled, cemetery fulfilled, or vendor fulfilled.
- Package completion requires fulfillment evidence, not only payment.
