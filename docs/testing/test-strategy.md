# Test Strategy — v0.3

## 1. Risk-based layers

Unit tests for invariants; feature tests for Actions/authorization; adapter contract tests; browser tests for critical journeys; performance/concurrency tests; security/privacy tests; recovery tests.

## 2. Stakeholder MVP browser suites

### E2E-HOME

- Exact four menu labels and order.
- Desktop/mobile navigation.
- Public route accessibility.
- Gate explanatory state and customer-service CTA.

### E2E-BOOK

- Steps 1–9, progress, back/forward, autosave/resume.
- Five launch locations.
- TPU/TPS detail and Google Maps link.
- Makam Baru/Tumpang/Urgent/Pre-Need conditional behavior.
- Exact service catalog.
- Quote line items.
- Customer/deceased forms and secure upload.
- Online payment and manual fallback.
- Confirmation, invoice, notification state, and next action.

### E2E-MKT

- Exact category/product coverage.
- Product variant, cart, service area, schedule, fee.
- Single-vendor cart constraint.
- Online/manual checkout.
- Vendor accept/process/status/evidence.
- Customer tracking and vendor transaction history.

### E2E-REN

- Six visible steps.
- Fuzzy search, no-result/manual assistance.
- Tariff source and timestamp.
- Online/manual payment.
- Confirmation, invoice, and duplicate prevention.

### E2E-FAQ

- Six categories.
- Filter/search/detail/related content.
- Publish/unpublish and no-draft leakage.
- Customer-service CTA.

### E2E-ADMIN/VENDOR

- All required dashboard modules.
- Query scope and sensitive action audit.
- Transaction history and payout visibility.

## 3. Critical domain tests

| Flow | Required tests |
|---|---|
| Capability | invalid combinations, fallback |
| FuneralCase | SLA/escalation, handover, operator silence |
| Quote/payment | immutable version, guard, signature, mismatch, replay, manual verification |
| Documents | validation, quarantine, signed URL, audit |
| Marketplace | price snapshot, vendor scope, paid != complete |
| Renewal | deduplication, external marking, data gate |
| Notification | outbox idempotency, recipient scope, channel fallback |
| FAQ | publishing authorization and gate-sensitive content |

## 4. Performance targets

- Fuzzy search <500 ms at 100,000 representative records.
- 10,000-row import through queue with row-level errors.
- Signed document URL <=300 seconds.
- Homepage and wizard performance budgets are established before production and measured on representative mobile networks.

## 5. Test data

Use synthetic Indonesian names, addresses, typos, blocks, schedules, and documents. Never use real identity/death documents or production payment payloads.

## 6. Traceability

Every row in `docs/domain/traceability-matrix.md` must have linked test evidence before release.

## 7. Technical hardening suites

### Version/compatibility

- CI runs on pinned PHP 8.5 and validates Composer/Node lockfiles.
- Framework/Filament/Livewire upgrades receive a dedicated compatibility branch and browser suite.

### Queue/outbox

- commit succeeds but dispatch process dies;
- publisher retries and concurrent claims;
- duplicate event delivery;
- critical queue isolated during 10k import;
- Horizon graceful termination and retry behavior.

### Financial

- balanced journal batch and reversal;
- merchant/amount/currency mismatch;
- duplicate/out-of-order webhook;
- partial refund allocation;
- vendor payable eligibility/hold/payout;
- reconciliation exception creation and resolution.

### Database/recovery

- migration expand/contract compatibility;
- backup restore and critical invariant checks;
- pooled versus direct PostgreSQL connection;
- database failover/reconnect behavior where test environment supports it.

### Security/files/auth

- MFA enrollment/challenge/recovery/session revocation;
- recent re-authentication on sensitive actions;
- quarantine, MIME mismatch, infected/suspicious/scanner outage;
- no signed URL before accepted state;
- cross-panel and cross-record authorization.

### Performance

Run the profiles in `docs/operations/performance-and-capacity.md` and retain evidence.
