# Tasks — Funeral Marketplace and Vendor Portal

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention. Only this top-level functional checklist is annotated; the "Canonical product codes" and "Design system" sections below have their own, separately-scoped task lists that are not tied to a single numbered acceptance criterion.

- [ ] Implement product/category/variant data model. _Requirements: 1, 2_
- [ ] Implement gravestone configurator schema and preview. _Requirements: 2_
- [ ] Implement cart and multi-vendor order decomposition. _Requirements: 3, 4, 14_
- [ ] Implement schedule and region delivery pricing. _Requirements: 2_
- [ ] Create vendor panel and query-level policies. _Requirements: 5, 9_
- [ ] Implement vendor order status and evidence upload. _Requirements: 7, 13_
- [ ] Implement vendor calendar/availability. _Requirements: 6_
- [ ] Implement transaction recap and payout records. _Requirements: 8_
- [ ] Implement manual payout proof workflow. _Requirements: 11_
- [ ] Add payout provider adapter behind feature flag. _Requirements: 11_
- [ ] Add cross-vendor isolation tests. _Requirements: 9_

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

`marketplace-catalog.md` §"Required variant attributes where applicable": size, material, color, inscription text, calligraphy style, preview/reference image. Applies to `GRAVESTONE_*` (material, colour, inscription, calligraphy style) and `FLOWER_*` (size, preview image) as configured. **Do not invent additional variant axes** without a catalogue change.

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
- [ ] Define the nine product codes as one enum derived from the catalogue; seeders and tests consume the enum, never a literal list.
- [ ] Add a CI check that the enum and `marketplace-catalog.md` agree, so drift fails the build.
- [ ] Resolve the category-code OPEN QUESTION with the product owner before building `/marketplace/kategori/{categorySlug}`.
- [ ] Verify no product label or code is restated in a component, view, Filament Resource, validation rule, or fixture.
- [ ] Confirm the single-vendor checkout constraint is enforced across categories, not just within one.

### NOT TESTED

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

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Resolve all vendor and order statuses through `StatusIntent` (§3.7); render payment and fulfilment as two separate indicators.
- [ ] Implement the single-vendor conflict modal per §3.4; never silently drop cart items.
- [ ] Implement all ten required states for PUB-020…024 and the vendor panel screens.
- [ ] Confirm the Filament vendor panel inherits tokens (§8.3) rather than defining its own palette.
- [ ] Keep evidence files private; no thumbnail or preview of an unscanned upload (§6.7).
- [ ] Verify accessibility (§7) on both surfaces; vendor tables reflow to cards on mobile.
