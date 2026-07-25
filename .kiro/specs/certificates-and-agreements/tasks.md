# Tasks — Certificates and Agreements

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Define document types and issuer scopes. _Requirements: 1, 7_
- [ ] Implement versioned agreement/acceptance. _Requirements: 1, 2_
- [ ] Implement certificate eligibility and issue workflow behind gate. _Requirements: 3, 4_
- [ ] Implement revoke/replace and delivery tracking. _Requirements: 4, 5, 6_
- [ ] Add numbering, authorization, duplicate, and immutable-history tests. _Requirements: 4, 5, 7_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

This spec is the **primary consumer of the print/document token layer** — `tokens.css` §5 (`@media print`) and design-system.md §8.5. Certificates, agreements, invoices, and receipts are rendered documents, not screens, and they have their own token overrides.

### Primitives and tokens — screen

| Element | Primitive | Tokens |
|---|---|---|
| Certificate/agreement record | `<x-mk.card>` §3.3 | `--radius-lg`, `--mk-border-subtle` |
| Document number | monospace | `--font-mono`, copyable; unique per issuer/type (AC7) |
| Type / version badge | `<x-mk.badge intent=neutral>` §3.6 | version visible wherever acceptance is captured (AC2) |
| Status badge | `<x-mk.badge>` §3.6 + §3.7 | see mapping below |
| Acceptance capture | `<x-mk.field>` checkbox + `<x-mk.modal>` §3.4 | **bound to the exact version** (AC2); version badge shown at the moment of acceptance |
| Issue / revoke / replace | `<x-mk.modal>` §3.4 | revoke uses `danger` confirm, reason captured, **destructive button not default-focused**; issuer-role restricted (AC4) |
| History timeline | `<x-mk.card>` stacked §3.3 | AC5 — reissue preserves earlier history; render superseded versions as `neutral`, not hidden |
| Delivery status (AC6) | `<x-mk.badge>` §3.6 | `success` delivered · `pending` sending · `neutral` channel unavailable. **Never claim a delivery without delivery state** |
| External certificate reference (AC8) | `<x-mk.alert intent=info>` §3.8 | must **not** claim platform issuance |

### Primitives and tokens — print/PDF

Per design-system.md §8.5 and `tokens.css` §5:

| Concern | Token/rule |
|---|---|
| Body typeface | `--font-display` (serif) — gravitas is appropriate for a certificate |
| Colour | print overrides force black text, white surfaces, grey rules; **no brand fills, no elevation** |
| Shadows | all `--shadow-*` become `none` under `@media print` |
| Page | `@page { size: A4; margin: 18mm 16mm; }` |
| **Signed URLs** | `a[data-signed-url]::after { content: "" }` — **never print a signed document URL.** It expires in 5 minutes and every access is audited |

### Status → intent

Register in the shared `StatusIntent` helper (design-system.md §3.7). Certificate eligibility is **separate from order payment status** (AC3), so eligibility and payment render as **two indicators**, never merged.

Draft `neutral` · Pending issuance `pending` · Issued `success` · Delivered `success` · Revoked `danger` · Replaced/superseded `neutral`

### Required UI states

All ten states apply — design-system.md **§6**.

> **Gap:** this spec has **no screen-inventory ID**. The customer-facing document view and the issuer workflow both need entries in [`screen-inventory.md`](../../../docs/product/screen-inventory.md).

| Concern | State notes |
|---|---|
| loading | §6.1 skeleton for document list |
| empty | §6.2 — "Belum ada dokumen" + what will produce one |
| validation | §6.3 — numbering conflict surfaces inline, not as a server error |
| **authorization** | §6.4 — AC6: customer sees delivery/issuance status **without exposing restricted source documents**; denial must not reveal existence |
| provider unavailable | §6.5 — file-generation or signature provider down → `pending`, **never `issued`** |
| **duplicate/retry-safe** | §6.6 — AC7 number uniqueness: a retried issue command must render the **same** certificate, never a second number |
| pending | §6.7 — awaiting eligibility, generation, or signature; never styled as success |
| success | §6.8 — quiet issuance confirmation |
| support | §6.10 |
| responsive | §4.3 — document metadata legible at 320 px; the PDF itself is A4 |

### Gate constraint

`G-CERT-01` is closed. While closed, no platform-issued certificate may be rendered as issued; AC8 external references must be clearly marked as not platform-issued (`intent=info`).

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Build the print stylesheet from `tokens.css` §5 / design-system.md §8.5; serif body, no brand fills, no shadows.
- [ ] Ensure signed document URLs are never printed or embedded in a PDF.
- [ ] Render certificate eligibility and payment status as two separate indicators (AC3).
- [ ] Show the exact version badge wherever acceptance is captured (AC2).
- [ ] Render superseded versions as `neutral` rather than hiding them (AC5).
- [ ] Add screen-inventory entries for the customer document view and the issuer workflow.
- [ ] Implement all ten required states per the table above.
