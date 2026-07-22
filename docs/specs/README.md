# Feature Specifications — v0.3

Canonical Kiro-compatible specifications are stored in `../../.kiro/specs/`. Each feature contains:

- `requirements.md`
- `design.md`
- `tasks.md`

## MVP-required public specs

- `public-home-and-navigation`
- `public-booking-wizard`
- `cemetery-directory-and-availability`
- `booking-and-order-orchestration`
- `funeral-marketplace-and-vendor-portal`
- `renewal-and-grave-registry`
- `public-faq`
- `admin-operations`

Dashboard Vendor is covered by `funeral-marketplace-and-vendor-portal`.

## Status interpretation

- `Authority`: derived from RKS or explicit Stakeholder Workflow MVP.
- `Proposed`: benchmark-derived enhancement.
- `Optional/Gated`: activated only after legal, data, privacy, operational, or provider approval.

A gated external capability must use the fallback defined in `docs/product/mvp-scope.md`; it cannot silently remove a required MVP route or step.
