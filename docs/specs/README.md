# Feature Specifications — v0.7

**v0.7 (25 July 2026):** added the Platform foundation specs section and the dependency rule.

## Document versioning convention

Recorded 25 July 2026 for finding L-4 (document version inconsistency).

- Each document carries its own version, reflecting when that document's **content** last changed.
- The documentation **package** version is separate; `../../CHANGELOG.md` is authoritative for it (currently v0.6, also stated in `../../README.md` and `../../AGENTS.md`).
- A document version is bumped only when its content changes; an unchanged document keeps its version even when the package version moves ahead.
- A per-document version lower than the package version is therefore expected, not a defect. State the package baseline the document was last reviewed against instead of faking a bump.

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

## Platform foundation specs

Cross-cutting foundations every feature spec depends on. Added 25 July 2026 — they were previously consumed by many specs and owned by none, which left booking Steps 8–9 unbuildable. See `docs/planning/kiro-specs-analysis.md` §2.2.

- `platform-identity-and-access` — session auth, mandatory TOTP MFA, re-authentication, panel access, query scope (K1/K2)
- `platform-payment-adapter` — payment guard, hosted checkout, durable idempotent webhooks, manual fallback (K3–K5)
- `platform-notifications` — notification matrix, recipient scope, per-channel delivery state (K7)
- `platform-document-vault` — quarantine-first upload, fail-closed malware scan, 5-minute signed URLs, access audit (K6)
- `platform-audit` — append-only audit; required by 13 feature specs (K8)
- `platform-feature-gate` — the 17 gates and 18 flags, server-side, deny-by-default, documented fallbacks
- `platform-outbox` — transactional outbox, versioned envelope, at-least-once delivery, queue priorities
- `platform-financial-ledger` — balanced append-only journal, payable/payout separation, reconciliation

**Dependency rule:** a feature spec may consume a foundation but must not redefine one. Where a foundation owns a table or a state contract, the consuming spec references it.

## Status interpretation

- `Authority`: derived from RKS or explicit Stakeholder Workflow MVP.
- `Proposed`: benchmark-derived enhancement.
- `Optional/Gated`: activated only after legal, data, privacy, operational, or provider approval.

A gated external capability must use the fallback defined in `docs/product/mvp-scope.md`; it cannot silently remove a required MVP route or step.
