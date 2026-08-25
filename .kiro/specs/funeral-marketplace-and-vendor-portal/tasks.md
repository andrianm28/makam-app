# Tasks — Funeral Marketplace and Vendor Portal

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention. Only this top-level functional checklist is annotated; the "Canonical product codes" and "Design system" sections below have their own, separately-scoped task lists that are not tied to a single numbered acceptance criterion.

- [x] Implement product/category/variant data model. _Requirements: 1, 2_ — done 26 Jul 2026 (Sprint 4 S4-T1, master-data batch): `products`/`product_variants` schema, 9 real codes seeded, CI green
- [ ] Implement gravestone configurator schema and preview. _Requirements: 2_ — **partial**: variant *schema* done (size/material/color/calligraphy style/inscription/preview-image-path columns, 6 example rows seeded); S4-T8 added a **read-only** variant panel on `/marketplace/produk/{productCode}` that lists the seeded axes — evidence `ProductDetailRouteTest::test_a_gravestone_product_shows_its_seeded_variants` and `::test_the_calligraphy_product_shows_its_calligraphy_style_and_inscription_example`, CI run [`31248602859`](https://github.com/andrianm28/makam-app/actions/runs/31248602859) at commit `a150a3b`. The **interactive configurator and real preview rendering are still not built**; the shipped page deliberately suppresses placeholder preview paths rather than rendering a broken image (`::test_placeholder_variant_preview_image_paths_are_never_rendered`)
- [ ] Implement cart and multi-vendor order decomposition. _Requirements: 3, 4, 14_ — **not started, and deliberately so.** S4-T8 was browse-only (`sprint-plan.md` §S4-T8: "No cart, no checkout — those need payment and ledger (Tier 3) and are Sprint 11–12"); the **vendor portal half of that Sprint 11–12 scope shipped early with lane L10 (12 Aug 2026)** — cart/checkout did not. This is enforced by tests, not just intent: `MarketplaceIndexRouteTest::test_the_landing_page_offers_no_cart_or_checkout_affordance`, `::test_the_component_exposes_no_livewire_actions_to_call`, and `ProductDetailRouteTest::test_the_detail_page_offers_no_cart_or_checkout_affordance`. AC4's constraint is surfaced to the user as a *note* only (`::test_the_detail_page_states_the_single_vendor_per_checkout_constraint_as_a_note`) — a note is not enforcement, and must not be read as AC4 being satisfied
- [ ] Implement schedule and region delivery pricing. _Requirements: 2_ — not started, and **currently unimplementable as specified**. Verified against the migrations 08 Aug 2026: `products` carries `code, category, name, description, base_price_idr, price_version, is_active, sort_order` (+ `vendor_name, photo_path` from the dummy-data migration) and `product_variants` carries `product_id, size, material, color, calligraphy_style, inscription_text_example, preview_image_path, sort_order`. There is **no schedule, service-area, delivery-fee, stock/availability, or evidence-requirement column on either table**, so five of AC2's required fields have nowhere to live yet. See PUB-021's row in `docs/product/screen-inventory.md` — a disclosed schema gap, not an oversight in S4-T8's build
- [x] Create vendor panel and query-level policies. _Requirements: 5, 9_ — shipped 12 Aug 2026 (lane L10, PR #30): dedicated Filament `/vendor` panel (`VendorPanelProvider`) with `VendorListings`, `VendorOrders`, `ServiceAreas`, `VendorAvailabilities` resources; query-level vendor scope via `App\Domain\Marketplace\Access\CurrentVendorScope` + the `VendorScopedTablePage`/`ScopesToCurrentVendor` concerns; cross-vendor isolation proven by `VendorPanelScopingTest`, `VendorIdentityTest`
- [ ] Implement vendor order status and evidence upload. _Requirements: 7, 13_ — **partial**: the order-status half shipped 12 Aug 2026 (`VendorOrderResource` edit form + six one-click transition actions on `/vendor/orders/{id}/edit`, all through the sole writer `App\Domain\Marketplace\Actions\UpdateVendorOrderStatus`; `VendorOrderStatusTransitionActionsTest`). The **evidence upload half is genuinely unbuilt**: `EvidenceList` (`/vendor/evidence`) renders stored `file_path` values only — deliberately no download, no upload, no quarantine-to-accepted promotion, because issuing signed expiring URLs belongs to DocumentVault (`EvidenceList.php` doc block). **Do not read the listing page as the upload**
- [x] Implement vendor calendar/availability. _Requirements: 6_ — shipped 12 Aug 2026: `VendorAvailabilities` resource over `vendor_availability` (`AvailabilityMode`, `VendorAvailability` model), `VendorAvailabilitiesTable`
- [x] Implement transaction recap and payout records. _Requirements: 8_ — shipped 12 Aug 2026: read-only `TransactionHistory` and `PayoutStatus` pages (`/vendor/transaksi`, `/vendor/payouts`) scoped to the current vendor, fed by platform financial references (`VendorPanelAccessTest`)
- [ ] Implement manual payout proof workflow. _Requirements: 11_ — not started: the platform-side manual payout (`Actions/ManualPayout` in `platform-financial-ledger`) records amount/proof/approver, but no vendor-facing proof-upload workflow exists (`VendorFulfillment/` remains scaffolding)
- [ ] Add payout provider adapter behind feature flag. _Requirements: 11_ — not started; `G-PAYOUT-01` stays closed and no automated transfer path exists (structural detector `NoAutomatedPayoutPathTest`)
- [x] Add cross-vendor isolation tests. _Requirements: 9_ — shipped 12 Aug 2026: `VendorPanelScopingTest`, `VendorIdentityTest`, `VendorCreateStampingTest`, `VendorEditStampingTest`, plus the `update`-path forged-move guard (`VendorOrderEditFormTest`)

## Canonical product codes

**Single source of truth:** [`docs/product/marketplace-catalog.md`](../../../docs/product/marketplace-catalog.md).

`AGENTS.md` — Marketplace: *"Support the exact catalog in `marketplace-catalog.md`."* and *"Do not duplicate canonical catalog data in multiple hand-maintained documents or code locations."*

The nine identifiers below are **quoted from the catalogue, not defined here.** They are listed so an implementer can find them; the catalogue remains authoritative. If this table and the catalogue ever disagree, **the catalogue wins and this table is the defect.**

### Products — 9 codes (catalogue `docs/product/marketplace-catalog.md` L7–L30)

| Category | Code | Label |
|---|---|---|
| Karangan Bunga | `FLOWER_BOARD` | Karangan Bunga Papan |
| Karangan Bunga | `FLOWER_PETAL_PACKAGE` | Paket Bunga Tabur |
| Batu Nisan | `GRAVESTONE_GRANITE` | Granit |
| Batu Nisan | `GRAVESTONE_MARBLE` | Marmer |
| Batu Nisan | `GRAVESTONE_CALLIGRAPHY` | Kaligrafi |
| Perawatan Makam | `GRAVE_CARE_MONTHLY` | Bulanan |
| Perawatan Makam | `GRAVE_CARE_QUARTERLY` | 3 Bulan |
| Perawatan Makam | `GRAVE_CARE_SEMIANNUAL` | 6 Bulan |
| Perawatan Makam | `GRAVE_CARE_ANNUAL` | Tahunan |

These nine are the **minimum** MVP catalogue (AC1) and match `ekspektasi-user` exactly — 3 categories, 9 products. See [`docs/planning/ekspektasi-vs-specs.md`](../../../docs/planning/ekspektasi-vs-specs.md) §1.3.

### Seeds and enums must derive from the catalogue

- Seeders, enums, and factories read these nine codes from **one** definition in code, traceable to the catalogue.
- Do **not** restate the product list in a Livewire component, a Blade view, a Filament Resource, a validation rule, or a test fixture. Reference the enum.
- Adding, renaming, or removing a code requires a catalogue change first, then a product-approval note — never a code-first change.

### Variant attributes

`marketplace-catalog.md` §"Required variant attributes where applicable": size, material, color, inscription text, calligraphy style, preview/reference image. Applies to `GRAVESTONE_*` (material, colour, inscription, calligraphy style) and `FLOWER_*` (size, preview image) as configured. **Do not invent additional variant axes** without a catalogue change. **Correction, 09 Aug 2026 (marketplace retrofit):** the `FLOWER_*` extension is this spec's own interpretive addition — the catalogue places the "Required variant attributes where applicable" block under **Batu Nisan only**, and `ProductVariant::booted()` structurally forbids a `FLOWER_*` variant row (`2026_07_26_180100_create_product_variants_table.php:30-43` reasons why). The code's reading is authoritative.

### Required product data

Per `marketplace-catalog.md` §"Required product data" (AC2). Referenced, not restated here — that document is authoritative for the full field list.

### Vendor processing statuses — 8 codes

Canonical in `marketplace-catalog.md` §"Vendor processing statuses": `MENUNGGU_VENDOR`, `DITERIMA_VENDOR`, `DITOLAK_VENDOR`, `DIPROSES`, `DIKIRIM_OR_DIJADWALKAN`, `SELESAI`, `KOMPLAIN`, `DIBATALKAN`.

Their visual mapping is in the "Vendor processing status → intent" table below and must resolve through the single `StatusIntent` helper. **`DIBAYAR` ≠ `SELESAI`** (AC12; `AGENTS.md` *"Paid does not mean completed"*).

### Checkout constraint applies to every code

`AGENTS.md`: *"MVP is one vendor per checkout."* Because a single category can span multiple vendors, a cart holding e.g. `FLOWER_BOARD` from vendor A and `GRAVESTONE_GRANITE` from vendor B must trigger the separate-checkout or explicit-split UX (AC4). It must **not** silently lose items. Product codes do not group by vendor — only `vendor_orders` allocation does.

### Exclusion

`AGENTS.md`: *"Do not implement land rights listing through generic marketplace code."* No plot, land, or grave-rights product may be added to these codes or to `marketplace_categories`; AC15 excludes it pending independent approval. Plot inventory is owned by [`plot-inventory-and-reservation`](../plot-inventory-and-reservation/tasks.md).

### ⚠️ OPEN QUESTION — category identifiers are undefined

```
$ grep -cE '`(CATEGORY|CAT)_' docs/product/marketplace-catalog.md
0
```

The catalogue defines **9 product codes but 0 category codes** — the three categories exist only as Markdown headings (`### Karangan Bunga`, `### Batu Nisan`, `### Perawatan Makam`). Meanwhile `information-architecture.md` defines the route `/marketplace/kategori/{categorySlug}`, which requires a stable slug.

**No category code or slug has been invented here.** Doing so would create a second, competing source of truth — the exact defect this section exists to fix. Required before the category route can be built:

1. Product owner adds category codes and public slugs to `marketplace-catalog.md`.
2. This spec then references them; it must not define them.

Until then, category routing is **BLOCKED**, not merely undocumented. Product browse by direct product code is unaffected.

### Tasks

- [ ] Replace AC1's English prose product list in `requirements.md` with a reference to `marketplace-catalog.md`, matching the pattern `public-booking-wizard` AC5 already uses for `service-catalog.md`. *(Requires an edit to `requirements.md` — out of scope for this file; raise as a spec-repair item.)*
- [x] Define the nine product codes as one enum derived from the catalogue; seeders and tests consume the enum, never a literal list. — done 26 Jul 2026 (`App\Domain\Marketplace\ProductCode`)
- [x] Add a CI check that the enum and `marketplace-catalog.md` agree, so drift fails the build. — done 26 Jul 2026 (`ProductCatalogueSeedTest` re-parses the live catalogue document at test time and asserts agreement; runs in CI on every push)
- [ ] Resolve the category-code OPEN QUESTION with the product owner before building `/marketplace/kategori/{categorySlug}`. — **still open and still BLOCKED after S4-T8.** Re-verified 08 Aug 2026: `docs/product/marketplace-catalog.md` still defines nine product codes and zero category codes, so no slug was invented. The route is not merely unbuilt, it is deliberately unregistered, and a test holds that line: `MarketplaceIndexRouteTest::test_the_category_slug_route_is_still_blocked_and_deliberately_unregistered` (CI run `31248602859`). **Worked around, not resolved:** S4-T8 filters by the query parameter `?kategori=<KEY>` where `<KEY>` is a key `App\Domain\Marketplace\MarketplaceProductCategory` already defines and `products.category` already stores — an internal key, explicitly *not* a public slug, expected to be replaced once the catalogue gains real slugs. Owner: product owner, per steps 1–2 above. This checkbox stays `[ ]` until the catalogue changes
- [x] Verify no product label or code is restated in a component, view, Filament Resource, validation rule, or fixture. — done 08 Aug 2026 (agent team, S4-T8), CI run `31248602859`. This was the line item explicitly waiting on "a consuming UI batch (S4-T8) adds more call sites", and that batch has now landed: `MarketplaceIndex` and `ProductDetail` read `ProductCode` / `MarketplaceProductCategory` rather than any literal list, and the tests assert over the enum too — `ProductDetailRouteTest::test_every_seeded_product_code_has_a_reachable_detail_page` and `MarketplaceIndexRouteTest::test_every_known_category_key_filters_to_exactly_its_own_products` both iterate the enum instead of hardcoding nine names. `ProductCatalogueSeedTest` continues to re-parse the live catalogue at test time, so drift still fails the build
- [ ] Confirm the single-vendor checkout constraint is enforced across categories, not just within one. — still not applicable; no cart/checkout exists (Sprint 11–12). S4-T8 *states* the constraint on the product detail page as a note, which is disclosure, not enforcement — do not read that test as satisfying this item

### NOT TESTED

**Correction, 08 Aug 2026.** The first bullet below was written when this repository held no application code. It is kept verbatim rather than rewritten, per this repository's convention for superseded reasoning (`docs/planning/sprint-plan.md` findings N-10/N-11), but it is **now false**: the enum (`App\Domain\Marketplace\ProductCode`), the seed migration (`2026_07_26_180200_seed_marketplace_products_and_variants.php`), and tests all exist and run in CI. Every other bullet in this section still stands as written — none of them was closed by S4-T8.

- The nine codes were **read from the catalogue**, not exercised — no enum, seeder, migration, or test exists (zero application code in this repository).
- Whether these nine are **commercially complete** is not verified; AC1 calls them the *minimum* MVP catalogue, and the catalogue may grow.
- The category/label ↔ code mapping in the table above is a **transcription** of the catalogue. It has not been reviewed by the product owner.
- Whether `GRAVE_CARE_*` products are ordered through this marketplace, through [`recurring-care-subscriptions`](../recurring-care-subscriptions/tasks.md), or both, is **not settled** — see `ekspektasi-vs-specs.md` §4 D3.
- Whether a Step-4 booking add-on (`FLOWERS`, `GRAVESTONE` in `service-catalog.md`) is fulfilled by the same vendor pipeline as `FLOWER_*` / `GRAVESTONE_*` here is **not specified anywhere** — see `ekspektasi-vs-specs.md` §4 D4.

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

This spec spans two surfaces — the public marketplace (PUB-020…024) and the Filament vendor panel (VND-001…080). **Both use the same tokens**; the vendor panel must not be styled independently (design-system.md §8.3).

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Category / product cards | `<x-mk.card as=a interactive>` §3.3 | `--radius-lg`, `--shadow-sm`, hover `--shadow-md` |
| Product grid | layout §4.3 | 1 → `--breakpoint-sm` 2 → `--breakpoint-lg` 3 → `--breakpoint-xl` 4 |
| Variant selector | `<x-mk.field>` §3.2 | `--mk-border-interactive`, `--mk-control-h-md`, 44 px targets |
| Gravestone configurator | `<x-mk.field>` + preview card | inscription text uses `--text-base`; preview frame `--radius-2xl` |
| Price / delivery fee | table §3.5 | `text-right tabular-nums`, `--font-mono`; total `--font-weight-bold` |
| Cart | `<x-mk.card>` + `<x-mk.table>` §3.5 | mobile: table becomes stacked cards below `--breakpoint-md` |
| **Single-vendor conflict dialog** | `<x-mk.modal>` §3.4 | bottom sheet below `--breakpoint-md`; footer `flex-col-reverse` so the primary action sits in thumb reach |
| Vendor order status | `<x-mk.badge>` §3.6 + §3.7 | see mapping below |
| Evidence upload | upload states §6.7 | `pending` while scanning; **evidence files are private** |
| Payout status | `<x-mk.badge>` §3.6 | `--mk-intent-*`; payout actions restricted to finance roles |
| Bulk export | `<x-mk.button variant=secondary>` §3.5 | **never `primary`**, never adjacent to a benign action — privileged action requiring recent re-authentication |

### Vendor processing status → intent (normative)

From design-system.md §3.7. Resolve through the single `StatusIntent` helper — never `match` on the enum in Blade or in a Filament closure.

| Status | Intent | Icon |
|---|---|---|
| `MENUNGGU_VENDOR` | `pending` | clock |
| `DITERIMA_VENDOR` | `info` | check-circle |
| `DITOLAK_VENDOR` | `danger` | x-circle |
| `DIPROSES` | `info` | cog |
| `DIKIRIM_OR_DIJADWALKAN` | `info` | truck |
| `SELESAI` | `success` | check-badge |
| `KOMPLAIN` | `danger` | exclamation-triangle |
| `DIBATALKAN` | `neutral` | slash |

> **`DIBAYAR` ≠ `SELESAI`** (AC12). Payment and fulfilment are separate states and must render as **two distinct indicators**, never merged into one "done" badge.

### Required UI states

All ten states apply — design-system.md **§6**.

| Screen | State notes |
|---|---|
| PUB-020 landing | loading §6.1 · **empty category** §6.2 with `Lihat kategori lain` |
| PUB-021 product detail | variant states · schedule · **area unavailable** §6.2 with reason + alternative (`neutral`, not `danger`) |
| PUB-022 cart | **vendor conflict** → §3.4 modal offering separate checkout or split; must **not silently lose items** (AC4) · **changed price** → explicit reconfirmation |
| PUB-023 checkout | **validation error** §6.3 inline + summary (address, schedule, service area) — never clear entered data · online · manual fallback §6.9 (`intent=info`) · `pending` · failed §6.5 with a live fallback path |
| VND-010 product form | **validation error** §6.3 — price/variant/service-area errors render inline with `aria-invalid`, plus a summary alert on submit |
| PUB-024 order tracking | accepted / processing / completed / rejected via the mapping above; `pending` never styled as success |
| VND-040/050 | incoming order, status update, evidence upload §6.7 |
| VND-060/070 | transaction history, payout status — read-only, scoped |
| authorization | §6.4 — cross-vendor access returns an explanatory state, and **must not reveal whether the other vendor's record exists** |
| duplicate/retry-safe | §6.6 — repeated checkout submit must not create a second order |
| support | §6.10 on every transactional screen |
| responsive | §4.3 — vendor tables become stacked cards below `--breakpoint-md`; horizontal scrolling on a transaction list is a usability failure |

### Constraint — MVP is one vendor per checkout

`AGENTS.md`, AC4, and this spec's `design.md` all state one vendor per checkout for MVP. The UI must make the constraint **explicit** and offer separate checkout or an explicit split. Do not build multi-vendor decomposition UX; AC14 lists what must exist first.

### Tasks

Reviewed 08 Aug 2026 against the shipped S4-T8 code and CI run `31248602859` (commit `a150a3b`), re-reviewed 13 Aug 2026 after the L10 vendor panel shipped (PR #30). S4-T8 built **PUB-020 and PUB-021 only**; items touching the cart or checkout stay `[ ]` for the original reason, but the vendor-panel items were re-scored against the shipped `/vendor` panel.

- [x] Reference tokens for all colour/spacing/type; zero hardcoded values. — done 08 Aug 2026 (agent team, S4-T8), CI run `31248602859`, job **Docs and design gates**: `ci/verify-docs.sh` scans `resources/` and `app/` for hardcoded hex/px/ms/shadow and Tailwind arbitrary values, and passed with both marketplace views in the tree. Scope: the two public browse views. The vendor panel shipped 12 Aug 2026 (L10) and passes the same gate in CI (PR #30's runs)
- [ ] Resolve all vendor and order statuses through `StatusIntent` (§3.7); render payment and fulfilment as two separate indicators. — **partial**: the vendor panel shipped 12 Aug 2026 (L10) with `App\Filament\Vendor\Support\VendorOrderStatusPresenter`, which centralises labels and Filament badge colours in one place (walking `VendorProcessingStatus::KNOWN_STATUSES`, so `/vendor/orders` and `/vendor/transactions` cannot render the same status differently) — but it resolves to its **own** badge-colour mapping (`SELESAI` → `success`, per `VendorOrderStatusPresenter::colour()`), **not** through `App\Support\Design\StatusIntent`. The "single resolver consumed by public, admin, and vendor surfaces" half therefore stays open for the vendor surface. The `DIBAYAR`/`SELESAI` separation holds because the vendor order's own status list (`VendorProcessingStatus`) is what the panel displays, distinct from payment state (`VendorProcessingStatusTest`; `AGENTS.md` "Paid does not mean completed")
- [ ] Implement the single-vendor conflict modal per §3.4; never silently drop cart items. — not started; no cart exists (Sprint 11–12)
- [x] Implement all ten required states for PUB-020…024 and the vendor panel screens. — **partial, now including the vendor panel.** PUB-020/PUB-021 as previously listed (empty, validation, provider-unavailable, duplicate-safe, support; §6.4 deliberately n/a). **Vendor panel (L10):** §6.2 empty (empty order/payout/transaction lists render "Belum ada pesanan"/"Belum ada pencairan"/"Belum ada transaksi" with descriptions — `VendorOrdersTable`, `PayoutStatus`, `TransactionHistory`), §6.4 authorization (vendor-scoped denial is an explanatory state; `VendorPanelScopingTest::test_surface_is_closed_for_an_actor_with_no_vendor_grant` and `::test_surface_is_closed_for_a_guest` pin the closed default), §6.6 duplicate/retry-safe (status transitions are the sole-writer's single row change, no double-apply), §6.3 validation (status/notes form errors inline). **Still absent or unverified:** PUB-022, PUB-023, PUB-024 (cart, checkout, tracking — no screens), the vendor panel's §6.1/6.5/6.7/6.8/6.9 (no evidence upload, no provider path, no pending mutation flows), and no §6.10 support reference is asserted on vendor panel pages
- [x] Confirm the Filament vendor panel inherits tokens (§8.3) rather than defining its own palette. — done 12 Aug 2026 (L10): `VendorPanelProvider` loads the same `tokens.css`-derived palette as `/admin` (`->colors()` from `app/Support/Design/generated/FilamentPalette.php`) and the same `viteTheme` (`resources/css/filament/admin/theme.css`), so no independent palette exists. Corrected 13 Aug 2026: previously "not applicable yet; no vendor panel exists"
- [ ] Keep evidence files private; no thumbnail or preview of an unscanned upload (§6.7). — not started; no upload path exists. The L10 `EvidenceList` renders stored quarantine `file_path` strings only — deliberately no link, thumbnail, or download (`EvidenceList.php` doc block; `ProductDetailRouteTest::test_placeholder_variant_preview_image_paths_are_never_rendered` covers seeded catalogue images, which are not evidence files). The private/no-preview rule is thus structurally honoured (nothing offers a preview) but there is no upload surface to test yet
- [ ] Verify accessibility (§7) on both surfaces; vendor tables reflow to cards on mobile. — **partial**. The colour-only half is done for PUB-020 and CI-green: `test_the_active_filter_chip_carries_a_non_colour_tick_not_just_a_colour_change` and `test_exactly_one_filter_chip_is_marked_current_at_a_time` (WCAG 1.4.1). **Touch targets, focus ring, and the mobile reflow are NOT TESTED** — no browser or headless harness exists in this repository, so no geometry was measured. The vendor panel ships with no accessibility-verified geometry either (L10)
