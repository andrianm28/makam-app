# Traceability Matrix — v0.4

## Status legend

- `Specified` — spec and screen exist; **no test evidence yet**.
- `Specified (gated fallback)` — `Specified`, and the user-facing step is required even when an external gate is closed (see section C).
- `Covered` — reserved for an item whose test exists **and passes**. `AGENTS.md` requires test evidence for this value, so it is unusable until tests land.

v0.4 — 25 July 2026, finding H-3: statuses in section B previously read `Covered` for items that were specified but untested; all 31 were corrected to `Specified`. The repository currently contains no test files.

## A. RKS authority

| RKS | Capability | Spec | Gate/control |
|---|---|---|---|
| K23 | TPU/TPS directory and map | `cemetery-directory-and-availability` | Admin master data |
| K24 | Availability | `cemetery-directory-and-availability` | Manual confirmation by default |
| K25 | New, overlapping, Urgent, Pre-Need | `public-booking-wizard`, `at-need-booking`, `pre-need-contracting` | Ops/legal gates |
| K26 | Basic/add-on services | `package-and-service-bundles`, service catalog | Versioned price/availability |
| K27 | Booking and status | `public-booking-wizard`, `booking-and-order-orchestration` | No early payment |
| K28 | Deceased data/documents | `public-booking-wizard`, booking orchestration | Private, short URL, audit |
| K29 | Funeral marketplace | `funeral-marketplace-and-vendor-portal` | Single-vendor MVP |
| K30 | Vendor portal | `funeral-marketplace-and-vendor-portal` | Query scope; payout gate |
| K31 | Renewal | `renewal-and-grave-registry` | Tariff source; external marking |
| K32 | Registry/search/reminder | `renewal-and-grave-registry` | Data/privacy gate |
| K33 | Recurring care | `recurring-care-subscriptions`, `grave-care-fulfillment` | Billing != fulfillment |
| K34 | Operator dashboard | `cemetery-operator-dashboard` | Non-blocking fallback |
| K35 | Admin dashboard | `admin-operations` | Audited changes |

## B. Stakeholder Workflow MVP

| ID | Expectation | Canonical doc/spec | Screen | Test family | Status |
|---|---|---|---|---|---|
| HOME-01 | Pemesanan Makam menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | Specified |
| HOME-02 | Layanan Pemakaman menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | Specified |
| HOME-03 | Perpanjangan menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | Specified |
| HOME-04 | FAQ menu | `public-home-and-navigation` | PUB-001 | E2E-HOME | Specified |
| BOOK-01 | Lima kota awal | directory + wizard | PUB-010 | E2E-BOOK-01 | Specified |
| BOOK-02 | TPU/TPS detail | directory | PUB-011 | E2E-BOOK-02 | Specified |
| BOOK-03 | Empat jenis layanan | wizard | PUB-012 | E2E-BOOK-03 | Specified |
| BOOK-04 | Basic/add-on services | service catalog + wizard | PUB-013 | E2E-BOOK-04 | Specified |
| BOOK-05 | Ringkasan | wizard/quote | PUB-014 | E2E-BOOK-05 | Specified |
| BOOK-06 | Data pemesan | wizard | PUB-015 | E2E-BOOK-06 | Specified |
| BOOK-07 | Almarhum + documents | wizard | PUB-016 | E2E-BOOK-07 | Specified |
| BOOK-08 | Payment | wizard/payment contract | PUB-017 | E2E-BOOK-08 | Specified (gated fallback) |
| BOOK-09 | Confirmation/invoice/notification | wizard + notification matrix | PUB-018 | E2E-BOOK-09 | Specified |
| MKT-01 | Flower categories | marketplace catalog | PUB-020/021 | E2E-MKT | Specified |
| MKT-02 | Gravestone categories | marketplace catalog | PUB-020/021 | E2E-MKT | Specified |
| MKT-03 | Care intervals | marketplace catalog | PUB-020/021 | E2E-MKT | Specified |
| MKT-04 | Checkout/payment/vendor processing | marketplace spec | PUB-022–024 | E2E-MKT | Specified |
| REN-01 | City | renewal spec | PUB-030 | E2E-REN | Specified |
| REN-02 | TPU/TPS | renewal spec | PUB-030 | E2E-REN | Specified |
| REN-03 | Grave search | renewal spec | PUB-031 | E2E-REN | Specified |
| REN-04 | Fee | renewal spec | PUB-032 | E2E-REN | Specified |
| REN-05 | Payment | renewal spec | PUB-033 | E2E-REN | Specified (gated fallback) |
| REN-06 | Confirmation/invoice | renewal spec | PUB-034 | E2E-REN | Specified |
| FAQ-01 | Cara memesan | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | Specified |
| FAQ-02 | Dokumen | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | Specified |
| FAQ-03 | Pembayaran | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | Specified |
| FAQ-04 | Perpanjangan | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | Specified |
| FAQ-05 | Pembayaran gagal | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | Specified |
| FAQ-06 | Customer service | FAQ catalog/spec | PUB-040/041 | E2E-FAQ | Specified |
| ADMIN-01 | Admin dashboard modules | `admin-operations` | ADM-* | E2E-ADMIN | Specified |
| VENDOR-01 | Vendor dashboard modules | marketplace/vendor spec | VND-* | E2E-VENDOR | Specified |

## C. Gate interpretation

`Specified (gated fallback)` means the user-facing step and outcome are required even when an external capability is inactive. A closed gate cannot be used to remove Step 8, hide the feature silently, or report a false success.
