# Brand Identity Adoption — Makam.co.id Design

**Date:** 17 Aug 2026
**Status:** Draft (design approved by user in chat 17 Aug 2026, pending written review)
**Scope:** Adopt the official Makam.co.id brand identity (figure-8 + 8-leaf mark, dark brown + leaf green, Poppins) across the token system, public web, Filament panels, print documents, and favicons. Resolves design-system OQ-01 (brand primary) and OQ-02 (existing identity); amends OQ-03 (typeface). Identity rebase only — no product, label, route, nav-order, booking-step, or status-model change.
**Authority for the identity:** stakeholder-supplied logo render + pasted *"Filosofi Logo Makam.co.id"* (17 Aug 2026): figure-8 = continuity/eternity; 8 radial leaves = life/growth/renewal; intersection = *"where life meets eternity"*; dark brown = earth, calm, stability, warmth, respect, humanist (deliberately "not too tech"); green = life, hope, sustainability, future green-cemetery positioning; Poppins = modern, clean, digital, friendly, professional.
**Depends on:** `resources/css/tokens.css` two-layer token model, `docs/design/design-system.md` v0.1, ADR-0028 (token-driven design system), `docs/design/verify-contrast.py`, `design:verify-filament-palette` CI check.
**External input required:** the logo source PNG committed to `docs/design/brand/source/` by the user (transparent background preferred; white-background render can be white-keyed but stays PROVISIONAL). Blocks task T3 only.

## 1. Goal

`design-system.md` v0.1 was written under OQ-02's explicit "no prior identity assumed" default: Petrol teal primary chosen *provisionally*, placeholder "M" monogram logo, empty `favicon.ico`. A real brand identity now exists and is authoritative. This phase rebases the palette onto it (Earth brown primary, Petrol retired; Leaf green as caged secondary), adopts Poppins as the display/heading voice while Inter keeps body/UI, replaces the placeholder logo with optimized raster assets everywhere it appears, and regenerates every derived surface (Filament palette, favicons, print) — with every Layer-2 semantic alias, component class recipe, status→intent mapping, and product contract untouched, and all 46 contrast pairs re-verified before merge.

## 2. In scope

1. **Palette rebase** in `tokens.css` Layer 1: new 11-shade **Earth** ramp (logo brown fixed at `primary-600` if AA holds — visual estimate ~9:1 — else at 700 with recipes re-pointed); `secondary-*` values replaced in place by a **Leaf** green ramp under the existing restricted-usage cage; `--mk-surface-warm` re-pointed `secondary-50 → primary-50`; every derived value flagged `/* PROVISIONAL — derived from logo render, awaiting official brand values */`.
2. **Typography**: `--font-display` = self-hosted Poppins 600 (OFL, latin subset, woff2, `font-display: swap`) for h1/h2, hero, and the header wordmark; Inter unchanged for body/UI/h3/h4; new `--font-document` = Source Serif 4 stack consumed by `print.css` (prevents printed documents silently becoming Poppins); Filament stays all-Inter.
3. **Logo asset pipeline**: one deterministic, idempotent build script (Node+sharp or Python Pillow — whichever the environment already provides; decision recorded in the plan) emitting mark-only 1×/2×/3× WebP+PNG, the stacked lockup, an inverse variant for dark surfaces (brown→white selective recolor, leaves kept; documented fallback = light chip behind the full-color mark), and the favicon set (`favicon.ico` 16/32/48, `apple-touch-icon.png` 180, `icon-192.png`, `icon-512.png`) into `public/brand/`.
4. **`<x-mk.logo>` rewrite**: `<picture>`+`<img>` with explicit width/height (CLS rule), contextual `alt` (empty when adjacent live-text wordmark present), props `size`, `variant` (`normal`|`inverse`), `wordmark` (bool); header/footer wiring; live-text wordmark `makam.co.id` (lowercase per brand render) in Poppins 600 — live text, not baked pixels (crisp, accessible, token-colored).
5. **Filament sync**: `AdminPanelProvider`/`VendorPanelProvider` PHP color arrays **regenerated** from `tokens.css` (OQ-09 manual-sync path; CI diff via `design:verify-filament-palette` stays the enforcement); panel `brandLogo()` pointed at the new asset.
6. **Print**: small brand mark in document headers (invoice, kwitansi, agreement, certificate); no other print change (§5 ink-economy rules stand).
7. **Governance artifacts**: **ADR-0034** (identity adoption; records the OQ-01 reversal of design-system §1.2a with the philosophy doc as authority); `design-system.md` section edits (§1.2 decisions a/b rewritten, §1.4, §7.1 re-measured, §9.2 MUST-NOT #7 reworded for the Leaf cage, §10 quick reference, §11 OQ-01/OQ-02 resolved + new **OQ-12** tracking official hexes/vector source, §12 evidence, §13 item 1); one short email brand-usage rule section (no templates exist — none built).

## 3. Out of scope

- Product surfaces: labels, routes, nav order, homepage section order, booking steps, marketplace/renewal flows — all unchanged by contract.
- `StatusIntent`, the §3.7 status→intent mapping, and every Layer-2 `--mk-*` alias **name** — values only.
- Email/WhatsApp template design or implementation (future track; rule section only).
- Dark mode (OQ-07 stays closed), illustration style, photography direction/sourcing, PWA manifest.
- Neutral-ramp warm shift (noted as a possible future refinement; deliberately excluded to keep the blast radius bounded).
- Official-hex confirmation and vector-source replacement — tracked as OQ-12 follow-up, not this phase.

## 4. Architecture

### 4.1 Palette

- **Method**: sample the logo brown/green from the committed source render (record the sampled values in the plan's evidence); generate each ramp on a constant-hue OKLCH lightness curve with low chroma (design tone: deep, low-chroma); hand-tune only where an AA gate requires it, and record every tune.
- **AA gates the new ramps must hold** (enforced by `verify-contrast.py`, pair list unchanged unless a usage is added): white on `primary-600` ≥ 4.5; `primary-600` link on white ≥ 4.5; focus ring `primary-600` ≥ 3.0; inverse focus `primary-300` on `primary-600` ≥ 3.0; white on `surface-inverse` (`primary-900`) ≥ 7 target (current: 14.40); filter-chip pair `primary-800` on `primary-100` ≥ 4.5. If the sampled brown cannot hold a gate at 600, the base moves to 700 and §3 recipes are re-pointed — recorded in the ADR.
- **Collision finding 1 — primary vs danger**: logo brown hue ~25–28° sits ~24° from `danger` (3°), failing the verifier's ≥30° semantic-separation check. Preferred resolution: tune `danger` hue to ≤357° (crimson-brick, luminance preserved → its pairs should hold) and re-verify. Weakening the assertion is forbidden (§9.4). If the tune measurably fails, the ADR records the chosen alternative instead of silently shipping.
- **Collision finding 2 — primary vs warning**: ~12–14° apart, but `warning` was never in the separation assertion and the structural mitigation already exists: a solid dark-brown CTA with white label never shares a shape with a pale-amber badge carrying icon + dark text (§7.5 no-colour-only-status). Resolution: documented reviewed-with-mitigation; no rule change.
- **Leaf cage**: `secondary-*` keeps Sandstone's old cage, re-justified against **success** (146°) proximity instead of warning proximity: 50–200 surface tints, 300–400 decorative rules/icons, 700–900 text on tints; **never** a fill, badge, button, or alert (§9.2 MUST-NOT #7 reworded, not weakened). Initial usage is limited to hero/trust-section decorative accents; nothing is recolored green in this pass. No new separation assertion is added for Leaf — the cage *is* the control.

### 4.2 Typography

- `--font-display: "Poppins", var(--font-sans)` fallback chain; `@font-face` Poppins 600 latin-subset in `app.css` + `<link rel="preload">` on the public shell. One weight only — the §4.6 font budget (≤60 KB initial route) is re-measured at build; if exceeded, the documented lever is tightening the Inter var subset, never silently shipping heavier pages.
- New `--font-document: "Source Serif 4", "Lora", ui-serif, Georgia, serif`; `print.css` body switches `var(--font-display) → var(--font-document)`.
- Usage: h1/h2 + hero + header wordmark = Poppins 600; h3/h4/card titles stay Inter semibold (Poppins' wide geometry hurts at small sizes); body/forms/tables = Inter; references = JetBrains Mono. Filament panels unchanged (all-Inter; brand voice is a public-surface concern).

### 4.3 Logo assets

- Source of truth for artwork: `docs/design/brand/source/` (user-committed PNG; transparent background preferred — white-keying the white-background render is the documented provisional fallback).
- Build script: deterministic (fixed input → byte-comparable outputs), idempotent, fails closed on missing source with a clear message; run locally, outputs committed to `public/brand/` (no on-host build tooling — AGENTS.md dev-staging constraint).
- Variants: mark-only (figure-8 + leaves cropped from the lockup) at 1×/2×/3× in WebP + PNG fallback; stacked lockup for footer/documents; **inverse** mark (brown→white selective recolor, leaves unchanged) for `primary-900` surfaces — if the recolor visually fails review, the documented fallback is a light rounded chip behind the full-color mark, and the ADR records which shipped.
- Favicons: `favicon.ico` (16/32/48 multi-size, replaces the current empty placeholder), `apple-touch-icon.png` (180), `icon-192.png`, `icon-512.png`; links wired in the public layout `<head>`.

### 4.4 `<x-mk.logo>` contract

- Props: `size` (px, default 32), `variant` (`normal`|`inverse`, default `normal`), `wordmark` (bool, default `true`).
- Markup: `<span class="inline-flex items-center gap-2">` → `<picture>` (WebP source + PNG `<img>` with explicit `width`/`height`, `loading` eager in header) → optional live-text wordmark `<span class="font-display font-semibold text-primary-800">makam.co.id</span>`.
- a11y: mark `alt=""` when the wordmark text is present (one accessible name, not two); `alt="makam.co.id"` when `wordmark=false`.
- Header consumes with `size=28` (mobile) / `32` (desktop) exactly as today — no header layout change. The layout footer (`layouts/app.blade.php`, `bg-primary-900`) today renders **no logo at all** — it gains a small brand row (inverse mark + wordmark) above the existing privacy/terms/contact nav. That is an *addition*, not a swap; IA §3 item 9 footer content is unchanged.

### 4.5 Surfaces

- **Public**: header logo swap; footer gains the inverse brand row (addition — none exists today); hero + trust-section decorative Leaf accents (300–400 only); `--mk-surface-warm` sections become warm cream automatically via the token re-point. Nothing else moves.
- **Filament**: both panel color arrays regenerated from `tokens.css` (never hand-edited — OQ-09); `brandLogo()` added; fonts untouched.
- **Print**: document-header mark (small, color); §5 ink-economy overrides otherwise stand.
- **Email**: new short rule section in `design-system.md` (hosted `/brand/` lockup URL reference only, brown-on-white only, no attachments — consistent with AGENTS.md notification rules). No templates built.

## 5. Docs & governance

- **ADR-0034 — Adopt Makam.co.id brand identity**: context (OQ-02 resolved by stakeholder-supplied identity), decisions D1–D14 mirroring §2, the two collision findings + resolutions, the raster-vs-SVG decision and its inverse-variant fallback, every PROVISIONAL flag, and the OQ-12 follow-up.
- `design-system.md` edits: §0.3 status note; §1.2 table + decisions (a) [teal→Earth reversal, philosophy quoted as authority] and (b) [Sandstone→Leaf, cage re-justified]; §1.4 typography; §7.1 re-measured ratios; §9.2 MUST-NOT #7 wording; §10 quick reference; §11 OQ-01/OQ-02 resolved, OQ-03 amended, OQ-12 added; §12 evidence tables re-run; §13 item 1 checked.
- `tokens.css`: header version → v0.2 with change summary; only Layer-1 values + the two font tokens + `--mk-surface-warm` re-point change. No alias renames.
- CHANGELOG entry.

## 6. Verification

- `python3 docs/design/verify-contrast.py` → exit 0 with the new ramps (all 46 pairs; the separation check passes via the danger-hue resolution). Any failing pair → fix the token, never the assertion (§9.4).
- Existing CI gates re-run clean: no-hardcoded-hex grep, no-arbitrary-values grep, no-raw-z-index, no-focus-suppression, `design:verify-filament-palette`, `blade:verify-content-survival`, `npm run build` (CSS emission + font preload), PHPUnit + browser suites.
- Browser tests: header/footer/homepage suites stay green, updated only where they assert the old placeholder SVG markup; favicon presence assertion added.
- Manual visual pass at 320/360/768/1024/1280 on homepage + one wizard step + one Filament page; screenshot evidence attached to the PR (the current system's §12 honestly lists "rendered visual appearance — NOT TESTED"; this phase closes that for the touched surfaces only).
- Font payload measured on the public shell and recorded against §4.6.

## 7. Task breakdown (for the implementation plan)

- **T1** ADR-0034 + palette revalue + danger-hue resolution + contrast green.
- **T2** Poppins pipeline (`@font-face`, preload, `--font-display`/`--font-document`, `print.css` switch) + budget measurement.
- **T3** Asset build script + all raster outputs + favicon set — **blocked on the user-committed source PNG**.
- **T4** `<x-mk.logo>` rewrite + header/footer wiring + browser-test updates.
- **T5** Filament palette regeneration + `brandLogo()` + CI diff green.
- **T6** `design-system.md` + `tokens.css` header + OQ table + CHANGELOG.
- **T7** Whole-branch verification (all gates, build, suites, manual visual pass) + two-tier review; one PR against `docs/design-system-and-planning`.

Worktree: `.worktrees/` off trunk, per AGENTS.md SDD; execution ledgered at `.superpowers/sdd/brand-identity/progress.md`; Kiro specs untouched (no Kiro AC is affected by identity).

## 8. NOT TESTED / honesty notes

- All brand color values are **PROVISIONAL** derivations from a render, pending official values (OQ-12). The white-keyed transparency (if needed) and the inverse-variant recolor are likewise provisional until reviewed.
- The logo render itself was reviewed as an image in chat; the source PDF (*Filosofi Logo Makam.co.id.pdf*) could not be parsed by the model — its content was supplied as pasted text and is treated as authoritative.
- No user testing of the new identity; tone rules in design-system §2 remain reasoned, not researched (unchanged from v0.1, still listed in its §12).
