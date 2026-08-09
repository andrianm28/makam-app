# Screen Inventory — MVP

## Revision note — 08 August 2026, Sprint 4 S4-T6/S4-T7/S4-T8

Three batches shipped on 08 Aug 2026 (CI run [`31248602859`](https://github.com/andrianm28/makam-app/actions/runs/31248602859), commit `a150a3b`) and this file understated all three. Rows below are reconciled against the routes and views that actually exist, not against what was planned. Per this repository's convention for superseded reasoning (`docs/planning/sprint-plan.md` findings N-10/N-11), corrections are annotated and dated rather than silently rewritten.

**PUB-011 is restated, not duplicated.** S4-T6 shipped the cemetery directory as a **standalone public page** (`/cemeteries` + `/cemeteries/{cemeterySlug}`), not as a step inside the booking wizard, and this row previously described only the wizard step. A *new* PUB row was considered and rejected: `.kiro/specs/cemetery-directory-and-availability/tasks.md`, `.kiro/specs/public-booking-wizard/tasks.md`, and `docs/design/design-system.md` (§ cemetery card, § empty-state table) **already all call this screen PUB-011**, with the same state list, so minting a second ID would fork one screen across two identifiers — the drift this file exists to prevent. PUB-011 is therefore one screen with **two entry points**: standalone (shipped) and embedded as booking Step 2 (not built). No ID is taken away from the wizard.

**PUB-080 was asserting something false.** It listed `/pemesanan-makam`, `/marketplace`, and `/perpanjangan` as coming-soon stubs. Verified against `routes/web.php` on 08 Aug 2026: `/marketplace` now resolves to `MarketplaceIndex` and `/perpanjangan` to `RenewalStart` — both real screens. Only `/pemesanan-makam` is still a stub (`BookingWizardComingSoon`), so the row is narrowed to it. The two removed routes are recorded here rather than dropped without trace.

**Route-vocabulary gap, unresolved and not owned here.** The shipped paths are `/cemeteries…`, matching the noun `docs/contracts/openapi.yaml` already uses, **not** `information-architecture.md`'s Indonesian route tree. Reconciling IA §1 with the shipped URIs belongs to whoever owns `information-architecture.md`; it is recorded, not fixed.

## Revision note — 09 August 2026, Sprint 4 S4-T4/S4-T5 (booking wizard Steps 1–5)

S4-T4/S4-T5 resumed 08 Aug 2026 and Steps 1–5 are now **built and reviewed on an unmerged branch** — not merged, not CI-verified, not deployed. The five booking rows below (PUB-010, PUB-011's wizard-embed entry point, PUB-012, PUB-013, PUB-014) and PUB-080 are corrected accordingly; each says "implemented, pending merge/CI" rather than "shipped", which in this file has so far meant deployed. The 08 Aug note above is left verbatim.

**PUB-011's two entry points are still two.** The wizard's Step 2 is its own list-plus-package-picker inside `/pemesanan-makam`, sharing `CemeteryPublicQuery` with the standalone `/cemeteries` page but not its Blade views. AC3's full field list (photo, address, Maps URL, facilities, attributed price range) is rendered on the **standalone** entry point only; the wizard embed currently shows name, type, and — where the cemetery has them — its package/class choices. That remains a real gap in the embed, recorded here rather than implied away by the row now reading as built.

## A. Public

| Screen ID | Screen | Key states |
|---|---|---|
| PUB-001 | Homepage | normal, urgent unavailable, degraded notification |
| PUB-010 | Booking Step 1 — Kota — `/pemesanan-makam` | loading, populated, no city — **implemented, pending merge/CI** (09 Aug 2026, S4-T4). All five launch cities are offered here in canonical order, so this screen no longer borrows PUB-011's filters as evidence |
| PUB-011 | **Cemetery directory — list + detail.** Two entry points: standalone `/cemeteries` and `/cemeteries/{cemeterySlug}` (**shipped** 08 Aug 2026, S4-T6) · booking Step 2 embed (**implemented, pending merge/CI** 09 Aug 2026, S4-T4 — name/type plus a package/class picker where the cemetery has active packages; AC3's fuller field list is on the standalone entry point only) | list, city/type filter, detail, no result — all shipped. Also shipped: validation error on an unknown filter (list still renders), authorization failure (draft slug 404s indistinguishably from an unknown one; "plot layout is not public for this cemetery" instead of a blank), provider unavailable, pending, support. **Absent:** duplicate/retry-safe, success, gated fallback banner — no mutation, no gate, no success outcome on a read-only browse surface |
| PUB-012 | Booking Step 3 — Jenis layanan | available — **implemented, pending merge/CI** (09 Aug 2026, S4-T4); all four service types are offered under their `mvp-scope.md` labels. **conditional and gated are absent**: no per-cemetery Makam Tumpang precondition and no Urgent/Pre-Need gate wiring exists yet — recorded, not implied |
| PUB-013 | Booking Step 4 — Layanan | package, add-on — **implemented, pending merge/CI** (09 Aug 2026, S4-T4); the 12 catalogue services render under their real names, basics mandatory. **unavailable item is absent** — no per-service availability signal exists in the catalogue yet |
| PUB-014 | Booking Step 5 — Ringkasan | valid quote — **implemented, pending merge/CI** (09 Aug 2026, S4-T4), as a computed presentation over current price versions with an honest "harga belum tersedia" when any price is missing. **changed price and expired quote are absent** — there is no persisted Quote row (AC8, out of scope for this batch), so there is nothing to expire or re-confirm against |
| PUB-015 | Booking Step 6 — Data pemesan | validation, authenticated prefill |
| PUB-016 | Booking Step 7 — Almarhum/dokumen | upload, scan pending, rejected file |
| PUB-017 | Booking Step 8 — Pembayaran | online, manual fallback, pending, failed |
| PUB-018 | Booking Step 9 — Konfirmasi | paid, manual verification pending, next action |
| PUB-020 | Marketplace landing — `/marketplace` (**shipped** 08 Aug 2026, S4-T8; browse only) | categories, empty category — both shipped, plus validation error (an unknown `?kategori=` explains itself and falls back to the full catalogue without leaking the domain exception message), provider unavailable, support. **Browse-only is test-enforced:** no cart or checkout affordance, and the component exposes no Livewire action to call. Category filtering is the query parameter `?kategori=<KEY>` (an internal key, not a public slug); `/marketplace/kategori/{categorySlug}` stays deliberately **unregistered** — `marketplace-catalog.md` defines 9 product codes and 0 category codes, and no slug was invented |
| PUB-021 | Product detail — `/marketplace/produk/{productCode}` (**shipped** 08 Aug 2026, S4-T8; read-only) | variant — shipped as a **read-only** panel (a product family with no variant axes says so rather than showing an empty state; placeholder preview image paths are suppressed rather than rendered broken); a deactivated code 404s indistinguishably from one that never existed. **`schedule` and `area unavailable` remain genuinely unimplementable**, not merely unbuilt: verified 08 Aug 2026 that `products` and `product_variants` carry **no schedule, service-area, delivery-fee, stock/availability, or evidence-requirement column**, so five of the marketplace spec's AC2 fields have nowhere to live. A disclosed schema gap — needs a migration before this row can be completed |
| PUB-022 | Cart | normal, vendor conflict, changed price |
| PUB-023 | Marketplace checkout | online/manual payment |
| PUB-024 | Marketplace order tracking | accepted, processing, completed, rejected |
| PUB-030 | Renewal Step 1–2 — `/perpanjangan` (**shipped** 08 Aug 2026, S4-T7) | city/cemetery selection — shipped, with all five launch cities in canonical order, a city with no published cemetery still offered (never silently omitted) and answered by a three-part empty state, draft cemeteries never offered, an unknown city code discarded rather than 404ing, a failed cemetery read degrading instead of 500ing, and the closed data gate rendering an honest banner **without removing the step** |
| PUB-031 | Grave search — `/perpanjangan/cari` (**shipped** 08 Aug 2026, S4-T7) | results, no result, privacy-limited — shipped as **three genuinely distinct states** (no-result · privacy-limited · gate-closed), held apart by assertions written as denials: the privacy-limited state never says "not found" and discloses no withheld name; the gate-closed state never implies the record does not exist; a search-backend failure is never reported as not-found. Also shipped: loading, validation error (a blank submission and an invalid death date are validation errors, *not* a no-result), authorization failure (a draft cemetery is unreachable through a held URL), support in every state. **Note:** AC4's < 500 ms at 100k records is **NOT TESTED** — nothing measures latency and no 100k-row fixture exists |
| PUB-032 | Renewal fee | source, last updated, mismatch warning — **no screen exists** (journey step 4, Sprint 13). The six-step stepper displays it, which is the correct product framing, but nothing renders a tariff or a fine |
| PUB-033 | Renewal payment | online/manual — **no screen exists** (journey step 5, Sprint 13) |
| PUB-034 | Renewal confirmation | invoice and due-date result — **no screen exists** (journey step 6, Sprint 13) |
| PUB-040 | FAQ list | category, search, no result |
| PUB-041 | FAQ article | article, related content, customer-service CTA |
| PUB-050 | Customer order status | timeline, next step, support |
| PUB-060 | Help/contact — `/bantuan` | channels, hours, emergency disclaimer (`.kiro/specs/help-centre-missing-route` — bugfix spec; no owning feature spec yet, see traceability §E) |
| PUB-070 | Kebijakan Privasi — `/privasi` | static policy sections, draft-pending-legal-review notice, customer-service CTA |
| PUB-071 | Syarat & Ketentuan — `/syarat-ketentuan` | static terms sections, draft-pending-legal-review notice, customer-service CTA |
| PUB-080 | Coming-soon stub — **no route left** as of 09 Aug 2026 (verified against `routes/web.php`: `/pemesanan-makam` now resolves to `BookingWizard`; `BookingWizardComingSoon` survives as deliberately-retained dead code, as `MarketplaceComingSoon` and `RenewalComingSoon` already do). S4-T4's branch replaces `/pemesanan-makam`'s `BookingWizardComingSoon` stub with the real wizard (PUB-010), the same way `RenewalStart` replaced `RenewalComingSoon`; the row is retained, not deleted, because the stub pattern is still the documented answer for a route whose screen is not built. **Pending merge/CI** — the stub is still what is deployed | not-yet-built explanation, contact channels, back-to-homepage and help CTAs |

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
