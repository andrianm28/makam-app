# Screen Inventory — MVP

## A. Public

| Screen ID | Screen | Key states |
|---|---|---|
| PUB-001 | Homepage | normal, urgent unavailable, degraded notification |
| PUB-010 | Booking Step 1 — Kota | loading, populated, no city |
| PUB-011 | Booking Step 2 — TPU/TPS | list, filter, detail, no result |
| PUB-012 | Booking Step 3 — Jenis layanan | available, conditional, gated |
| PUB-013 | Booking Step 4 — Layanan | package, add-on, unavailable item |
| PUB-014 | Booking Step 5 — Ringkasan | valid quote, changed price, expired quote |
| PUB-015 | Booking Step 6 — Data pemesan | validation, authenticated prefill |
| PUB-016 | Booking Step 7 — Almarhum/dokumen | upload, scan pending, rejected file |
| PUB-017 | Booking Step 8 — Pembayaran | online, manual fallback, pending, failed |
| PUB-018 | Booking Step 9 — Konfirmasi | paid, manual verification pending, next action |
| PUB-020 | Marketplace landing | categories, empty category |
| PUB-021 | Product detail | variant, schedule, area unavailable |
| PUB-022 | Cart | normal, vendor conflict, changed price |
| PUB-023 | Marketplace checkout | online/manual payment |
| PUB-024 | Marketplace order tracking | accepted, processing, completed, rejected |
| PUB-030 | Renewal Step 1–2 | city/cemetery selection |
| PUB-031 | Grave search | results, no result, privacy-limited |
| PUB-032 | Renewal fee | source, last updated, mismatch warning |
| PUB-033 | Renewal payment | online/manual |
| PUB-034 | Renewal confirmation | invoice and due-date result |
| PUB-040 | FAQ list | category, search, no result |
| PUB-041 | FAQ article | article, related content, customer-service CTA |
| PUB-050 | Customer order status | timeline, next step, support |
| PUB-060 | Help/contact — `/bantuan` | channels, hours, emergency disclaimer (`.kiro/specs/help-centre-missing-route` — bugfix spec; no owning feature spec yet, see traceability §E) |
| PUB-070 | Kebijakan Privasi — `/privasi` | static policy sections, draft-pending-legal-review notice, customer-service CTA |
| PUB-071 | Syarat & Ketentuan — `/syarat-ketentuan` | static terms sections, draft-pending-legal-review notice, customer-service CTA |
| PUB-080 | Coming-soon stub — `/pemesanan-makam`, `/marketplace`, `/perpanjangan` (temporary; each route is replaced by its real screen — PUB-010, PUB-020, PUB-030) | not-yet-built explanation, contact channels, back-to-homepage and help CTAs |

## B. Admin

| Screen ID | Screen |
|---|---|
| ADM-001 | Dashboard summary |
| ADM-010 | TPU/TPS list/detail |
| ADM-020 | Package/class/service/tariff |
| ADM-030 | Vendor and product management |
| ADM-040 | Booking orders and case detail |
| ADM-050 | Marketplace orders |
| ADM-060 | Renewal and grave record |
| ADM-070 | Payment/transaction/manual verification |
| ADM-080 | FAQ CMS |
| ADM-090 | Reports |
| ADM-100 | Audit and sensitive-action review |

## C. Vendor

| Screen ID | Screen |
|---|---|
| VND-001 | Vendor dashboard |
| VND-010 | Product/variant/price |
| VND-020 | Service area |
| VND-030 | Calendar/availability |
| VND-040 | Incoming order |
| VND-050 | Order detail/status/evidence |
| VND-060 | Transaction history |
| VND-070 | Payout status |
| VND-080 | Profile/account |

## D. Required UI states for every transactional screen

- loading;
- empty;
- validation error;
- authorization failure;
- provider unavailable;
- duplicate/retry-safe result;
- success;
- pending;
- customer-service escape hatch;
- responsive mobile layout.
