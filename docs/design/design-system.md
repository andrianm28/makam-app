# Design System — Makam.co.id

**Version:** v0.2 — brand identity adopted (ADR-0034), OQ-01/OQ-02 resolved; remaining sections **PROPOSED** (awaiting design + product approval)
**Date:** 25 Juli 2026
**Scope:** Public web (Blade + Livewire 4) and printed documents (invoice, kwitansi, agreement, certificate). **Filament 5 panels (`/admin`, `/vendor`) are explicitly OUT of scope for visual branding, 26 Aug 2026** — they use Filament's own stock default appearance, not this brand system; see §8.3 for the reversal record. Accessibility/behavioural rules elsewhere in this document that are not colour/font/logo branding may still be good practice for panels but are not enforced there by this document.
**Token source of truth:** [`resources/css/tokens.css`](../../resources/css/tokens.css)
**Contrast verifier:** [`docs/design/verify-contrast.py`](verify-contrast.py)

---

## 0. Status, authority, and how to read this document

### 0.1 Authority

This document is the **single source of truth for visual design decisions**. It does not create product scope. Where it touches product surface (labels, navigation, screens), the canonical authority remains, in `AGENTS.md` precedence order:

1. RKS K23–K35
2. [`docs/product/mvp-scope.md`](../product/mvp-scope.md)
3. Approved ADR + feature specs in `.kiro/specs/`
4. Benchmark extensions (only when explicitly approved)

**This design system may not introduce, rename, reorder, or hide a product label, menu item, route, or booking step.** Those come from [`information-architecture.md`](../product/information-architecture.md), [`mvp-scope.md`](../product/mvp-scope.md), [`service-catalog.md`](../product/service-catalog.md), [`marketplace-catalog.md`](../product/marketplace-catalog.md), and [`faq-catalog.md`](../product/faq-catalog.md). If a visual pattern here appears to require a product change, that is a defect in this document — raise it, do not implement it.

### 0.2 Relationship to existing baselines

| Concern | Canonical document | What this document adds |
|---|---|---|
| Stack versions | [`technology-baseline.md`](../architecture/technology-baseline.md) | How tokens wire into Tailwind 4.1 / Filament 5 |
| Routes, nav, homepage order | [`information-architecture.md`](../product/information-architecture.md) | Header, nav, and page-shell design |
| Screens + required states | [`screen-inventory.md`](../product/screen-inventory.md) | Concrete design for each required state |
| Booking fields + behaviour | [`booking-wizard-fields.md`](../product/booking-wizard-fields.md) | Stepper, autosave affordance, upload states |
| Order/vendor states | [`order-lifecycle.md`](../domain/order-lifecycle.md), [`marketplace-catalog.md`](../product/marketplace-catalog.md) | Status → visual intent mapping (§3.7) |
| Gated fallbacks | [`assumptions-and-gates.md`](../governance/assumptions-and-gates.md), `overview.md` §15 | Fallback-mode banner design (§6.9) |
| Performance targets | [`performance-and-capacity.md`](../operations/performance-and-capacity.md) | Front-end weight budget (§4.6) |
| Privacy of documents | [`security/file-upload-pipeline.md`](../security/file-upload-pipeline.md) | Quarantine/scan UI states (§6.7) |

### 0.3 Status of this version

`v0.1 PROPOSED`. The **token values are verified for accessibility** (§7.1, 49/49 pairs pass) but **no brand approval, no user testing, and no rendered build has occurred**. See §12 NOT TESTED and §11 OPEN QUESTIONS. Per `AGENTS.md`, nothing in this document may be reported as `PASS` beyond what §7.1 explicitly measured.

`v0.2` (17 Aug 2026): the official Makam.co.id brand identity was adopted per [ADR-0034](../adr/0034-adopt-makam-brand-identity.md). Earth brown replaces the provisional Petrol teal as `primary`, Leaf green replaces Sandstone as the caged `secondary`, and Poppins joins as the `--font-display` face (§1.4). **OQ-01** (brand primary) and **OQ-02** (existing identity) are resolved (§11). At this point every adopted brand hex was still **PROVISIONAL** pending **OQ-12** (official brand values, vector source, horizontal lockup) — see §11.

`v0.3` (21 Aug 2026): **OQ-12 resolved.** The real official logo (`docs/design/brand/source/logo.png`) replaced the earlier render-based estimate. Every Earth/Leaf hex in §1.2 is now sampled directly from that file (`docs/design/brand/sample-logo-colours.php`), and the full 50–950 ramp is derived from those anchors by `docs/design/brand/generate-ramp.php` (position-based lightness interpolation along the same curve the original ramp used, holding hue+saturation fixed — see that script's own doc comment for the method). No brand hex in this document is an estimate any longer. §7.1's contrast run was re-verified against the new values; see §11.

`v0.4` (26 Aug 2026): three governance additions from a kamboja.co.id competitive-benchmark review — see [ADR-0037](../adr/0037-price-emphasis-and-one-accent-one-purpose.md). §1.2(d) codifies "one accent, one purpose" as an explicit rule (with a matching §9.2 MUST NOT 13), generalizing the discipline §1.2(b) already applied informally to Leaf. §2.2 gains positive imagery guidance (candid warmth when people are present) alongside its existing constraints-only list. §3.3d reserves the `<x-mk.trust-badge-strip>` pattern as documentation only — explicitly not built, pending real partner/review content. `tokens.css` gains one new token, `--mk-text-price` (Earth `primary-800`), for confirmed monetary figures only; no existing token value changed and §7.1's 49 asserted pairs are unaffected.

---

## 1. Design tokens

Tokens live in **one file**: [`resources/css/tokens.css`](../../resources/css/tokens.css). This section documents intent and rationale; the file is authoritative for values.

### 1.1 Two-layer token model

```
┌─ LAYER 1: PRIMITIVES ──────────────── @theme { } ─────────────────┐
│  Raw ramps + scales. Generate Tailwind utilities AND :root vars.  │
│  --color-primary-600  --spacing  --text-base  --radius-md ...     │
└───────────────────────────────────────────────────────────────────┘
                              ▼ referenced by
┌─ LAYER 2: SEMANTIC ────────────────── :root { --mk-* } ───────────┐
│  Intent, not value. Plus concerns Tailwind has no namespace for.  │
│  --mk-text-default  --mk-border-interactive  --mk-z-modal ...     │
└───────────────────────────────────────────────────────────────────┘
```

**Rule:** component CSS references **Layer 2**. Tailwind utilities in Blade reference **Layer 1** (because that is what generates classes). Never reference a hex literal from either.

Why two layers: `--color-primary-600` says *what*, `--mk-text-link` says *why*. When brand review changes the link colour to `primary-700`, exactly one line changes and every link follows.

### 1.2 Colour — the palette and why it looks like this

| Family | Base (600) | Hue | Role |
|---|---|---|---|
| **Primary — "Earth"** | `#563B26` | 26° | Brand, primary CTA, links, active nav, focus ring |
| **Secondary — "Leaf"** | `#336B3E` | 132° | Surface tint + accent **only** (never a fill, badge, button, or alert) |
| **Neutral** | `#576060` | — | Text, borders, surfaces, dividers |
| **Success** | `#1C7A44` | 146° | `DIBAYAR`, `SELESAI`, upload accepted, autosave saved |
| **Warning** | `#9A6300` | 39° | All `MENUNGGU_*`, quote expiring, scan pending, Urgent |
| **Danger** | `#A32435` | 352° | `DITOLAK`, validation error, payment failed, file rejected — hue tuned **−11°** (was 3°) to restore ≥30° separation from Earth `primary` (ADR-0034 D4) |
| **Info** | `#3A4E9B` | 228° | Gated-fallback mode banners, neutral system notices |

**OQ-12 resolved 21 Aug 2026.** The real official logo (`docs/design/brand/source/logo.png`)
replaced the earlier render-based estimate. Every Earth/Leaf hex above is sampled directly from
that file (`docs/design/brand/sample-logo-colours.php`) and the full 50-950 ramp is derived from
those anchors by `docs/design/brand/generate-ramp.php` (position-based lightness interpolation
along the same curve the original ramp used, holding hue+saturation fixed — see that script's own
doc comment for the method). No value here is an estimate any longer.

**Three deliberate decisions worth stating, because they look wrong at first glance:**

**(a) Primary is Earth brown, not teal — OQ-01 resolved.** v0.1 chose Petrol teal as a deliberately *provisional* primary, made only because [OQ-02](#11-open-questions) had no prior identity to defer to, and deliberately "not green" so a brand CTA could never be confused with a `success` badge. On 17 Aug 2026 the stakeholder supplied the official Makam.co.id brand identity — a render plus the *Filosofi Logo Makam.co.id* philosophy text — which **reverses** that choice: *"dark brown = earth, calm, stability, warmth, respect, humanist (deliberately 'not too tech')."* [ADR-0034](../adr/0034-adopt-makam-brand-identity.md) records the decision. The old green-CTA-vs-success-badge ambiguity that justified avoiding green as primary no longer needs a hue-avoidance answer — it is now avoided **structurally**: brand fills are Earth brown, and `secondary` (Leaf, below) is caged so it never fills a button, badge, or alert regardless of how close its hue sits to `success`. **OQ-01, OQ-02, and OQ-12 are all resolved** — see the note above the palette table.

**(b) Secondary is constrained, not equal — Sandstone retired, Leaf inherits the cage.** The philosophy text reads the mark's eight radial leaves as "life, growth, renewal," so `secondary` becomes **Leaf** green (`#336B3E`, hue ≈132°, sampled from the official logo per the OQ-12 note above), replacing Sandstone. The restricted-usage cage is unchanged and, if anything, matters *more* now: Leaf sits only ≈14° from `success` (≈146°) — a comparable-magnitude collision to the one Sandstone previously had with `warning` (≈6.5°), not a smaller one — so any Leaf fill would misread as a status success. Secondary stays **restricted by usage**: shades 50–200 as surface tint, 700–900 as text on those tints, 300–400 as decorative rules/icons. It is **never** a filled badge, alert, or button. A restrained palette is also correct for this domain: one brand colour, one accent, four semantics, neutrals.

A `secondary-100` tile carrying a `secondary-800` icon (`<x-mk.icon-medallion tone="leaf">`, added 19 Aug 2026) is a surface-tint-plus-text usage, not a fill — it stays inside the cage, not an exception to it. It is `aria-hidden`, decorative only, and never appears adjacent to order/payment/availability data where a status reading could attach to it.

**(c) Danger is muted brick `#A32435`, not a bright red.** Users on this site are frequently in the first hours of bereavement. A saturated alarm red is hostile. `#A32435` holds 7.34:1 on white — it is *more* legible than a typical bright red while reading as serious rather than panicked. (Hue tuned −11° from the v0.1 value, `#A32A24`, by ADR-0034 D4 — see the danger row above.)

**(d) One accent, one purpose — governance rule, added 26 Aug 2026 (kamboja.co.id benchmark).** A competitive review of kamboja.co.id found it uses exactly one accent colour for urgency (its emergency hotline) and exactly one, different, accent colour for money/price figures — never reused for anything else. This design system already applies that discipline informally: (b) above cages Leaf to "surface tint + accent **only**," `danger` is reserved for errors/rejection, `warning` for waiting/urgency, `success` for confirmed payment, `info` for system notices. This paragraph makes the discipline an explicit, general rule rather than a set of family-specific carve-outs: **every accent/semantic colour token in `tokens.css` has exactly one designated purpose across the whole application, and must not be reused for a different semantic meaning elsewhere, even where it would be visually convenient.** A colour choosing to *look* like a good fit for a second meaning is not a licence to reuse it — a new meaning gets a new token (which may still reuse an existing family's shade, per §9.4, if that shade has no other plain-usage purpose yet — see `--mk-text-price` below) or, if none fits, a properly-verified new one. This is the same reasoning §1.2(b) already gives for why Leaf can never fill a status chip: a shared hue with a second meaning is read as the *first* meaning by a user under stress. Enforced at §9.2 MUST NOT 13.

**"Urgent" is an alias, not a new colour.** `information-architecture.md` §4 requires `Urgent` to have visual priority, and `AGENTS.md` forbids implying a service claim while gate `G-OPS-01` is closed. So `--mk-intent-urgent-*` aliases the warning family with a strong left border, and **must always ship with an explicit availability label**. No new hue, no implied promise.

### 1.3 Spacing — 4 px base

`--spacing: 0.25rem`. Tailwind 4 derives the entire scale from this one value, so every utility is a multiple of 4 px by construction.

| Token | px | Typical use |
|---|---|---|
| `1` | 4 | Icon–label gap, badge inset |
| `2` | 8 | Tight inline gap |
| `3` | 12 | Button horizontal padding (sm) |
| `4` | 16 | **Page gutter (mobile)**, card padding (mobile), input padding-x |
| `5` | 20 | Gap between form fields (`--mk-field-gap`) |
| `6` | 24 | Card padding (desktop), page gutter (md) |
| `8` | 32 | Page gutter (lg), gap between cards |
| `10` | 40 | **Section gap (mobile)** |
| `11` | **44** | **Minimum touch target** — `h-11` / `min-h-11` |
| `12` | 48 | Large control height |
| `13` | 52 | Primary CTA height (`--mk-control-h-lg`) |
| `16` | 64 | **Section gap (desktop)** |
| `24` | 96 | Hero vertical padding (desktop) |

Never use an odd value. `p-[13px]` is a lint failure (§9.5).

### 1.4 Typography

**Families**

| Token | Stack | Where |
|---|---|---|
| `--font-sans` | Inter var → system fallback | Public site only. **Superseded, 26 Aug 2026 (explicit owner decision):** admin/vendor Filament panels no longer use this token or any custom font at all — see §8.3's updated note. Brand voice, including typography, is a public-surface concern only. |
| `--font-display` | Poppins → Inter var → system fallback | `h1`/`h2`, hero, header wordmark **only** (ADR-0034 D7). Self-hosted via `@fontsource/poppins`, latin subset, weight **600 only**. `h3`/`h4` stay Inter — Poppins' wide geometry hurts at small sizes. Measured initial route payload (Poppins latin-600 + Inter latin, gzipped woff2, Task 2): **≈56 KB** (8 KB Poppins + 48 KB Inter) — under the §4.6 60 KB budget. |
| `--font-document` | Source Serif 4 / Lora → Georgia | Certificate/agreement/invoice documents **only**, consumed by `print.css` (§8.5) once it is built. Holds the value `--font-display` carried before ADR-0034, verbatim — added specifically so a document can never silently inherit the new Poppins brand face. |
| `--font-mono` | JetBrains Mono → system | Order reference, payment reference, audit IDs |

**Self-hosting is mandatory.** No Google Fonts, no CDN. Two reasons, both from existing baselines: staging is `noindex` and access-restricted ([`security-baseline.md`](../security/security-baseline.md) §Non-production isolation), and a third-party font request leaks the visitor's IP and referrer on pages where that visitor is arranging a funeral or uploading a death certificate. Subset to `latin` + `latin-ext`; Indonesian needs no extra ranges.

`--font-display` (Poppins) ships site-wide as part of `app.css` (§4.6 budget covers it as part of the initial route payload) and applies only to `h1`/`h2`; it is never used on the booking wizard's own body copy. `--font-document` is a separate, unrelated stack reserved for printed documents (§8.5) — it does not load on any public route today because `print.css` does not exist yet.

**Scale** (mobile-first; 16 px root; `rem`-based so browser zoom works)

| Token | Size | Line-height | Use |
|---|---|---|---|
| `text-2xs` | 11 | 16 | Legal footnote only. Never for interactive text. |
| `text-xs` | 12 | 16 | Badge, table meta, helper caption |
| `text-sm` | 14 | 20 | Helper text, table body, dense admin |
| `text-base` | **16** | 24 | **Body. FLOOR for every form input and label.** |
| `text-lg` | 18 | 28 | Lead paragraph, card title (mobile) |
| `text-xl` | 20 | 28 | `h4`, card title |
| `text-2xl` | 24 | 32 | `h3`, section title |
| `text-3xl` | 30 | 36 | `h2`, page title |
| `text-4xl` | 36 | 40 | `h1`, hero (mobile) |
| `text-5xl` | 48 | 48 | Hero (desktop, `lg:` and up) |

**Never below 16 px for form inputs.** iOS Safari auto-zooms inputs under 16 px, which breaks the wizard layout mid-entry — the worst possible moment.

**Weights:** `400` body · `500` UI labels/buttons (default) · `600` headings · `700` reserved for money totals and order references. Do not use `800`/`900`; heavy weights read as promotional.

**Measure:** body copy caps at `--container-prose` (640 px ≈ 70 ch). FAQ articles use it. Never full-bleed paragraphs.

### 1.5 Radius, elevation, z-index, breakpoints

**Radius** — default `--radius-md` (8 px) for buttons and inputs; `--radius-lg` (12 px) for cards and alerts; `--radius-xl` (16 px) for modals and bottom sheets. `--radius-full` is permitted **only** on avatars, stepper dots, and progress tracks. **No pill-shaped buttons** — the geometry reads playful, which is wrong here.

**Elevation** — five soft levels, tinted with the neutral hue rather than pure black. `xs` inputs · `sm` cards · `md` dropdowns/sticky footer · `lg` modal · `xl` bottom sheet. Restraint is the point: heavy shadows read as marketing.

**Z-index** — named layers, `--mk-z-*`. **A raw `z-index` or `z-[9999]` anywhere in the codebase is a lint failure.**

```
base 0 · raised 10 · sticky-cta 900 · dropdown 1000 · bottomnav 1100
header 1200 · backdrop 1300 · modal 1400 · popover 1500
toast 1600 · tooltip 1700 · skiplink 1800 · debug 1900
```

`sticky-cta` sits *below* `bottomnav` deliberately: the wizard's sticky "Lanjutkan" must never cover navigation.

**Breakpoints** (mobile-first — base styles are the 320 px case)

| Token | Width | What changes |
|---|---|---|
| — | 320 | Baseline. Single column, everything reachable. |
| `xs` | 360 | The dominant Jabodetabek Android viewport. Minor density gains only. |
| `sm` | 640 | 2-up service cards, 2-up product grid |
| `md` | 768 | Wizard gets summary sidebar; tables stop being cards |
| `lg` | 1024 | **Desktop nav replaces hamburger**; 3-up grids |
| `xl` | 1280 | `--container-content` max width reached |
| `2xl` | 1536 | No new layout — gutters grow only |

### 1.6 Motion

| Token | Duration | Use |
|---|---|---|
| `--mk-duration-instant` | 80 ms | Checkbox/radio check |
| `--mk-duration-fast` | 120 ms | Hover, focus, colour change |
| `--mk-duration-base` | 180 ms | Dropdown, tooltip, accordion |
| `--mk-duration-slow` | 260 ms | Modal, bottom sheet, wizard step change |
| `--mk-duration-slower` | 400 ms | Skeleton crossfade, page-level only |

Easing: `--ease-standard` default · `--ease-decelerate` entering · `--ease-accelerate` leaving · `--ease-emphasized` stepper advance.

**Nothing exceeds 400 ms. No bounce, no spring, no overshoot, no parallax, no autoplaying motion.** Bereavement context: motion should be invisible, never expressive.

`prefers-reduced-motion: reduce` collapses every duration to 1 ms in `tokens.css` §3 — the state change still happens, only the animation is removed (§7.6).

---

## 2. Brand and mood

### 2.1 Tone in one line

> **Tenang, hormat, terpercaya.** The interface should feel like a well-run public office staffed by kind people — calm, unhurried, unmistakably competent, never selling.

### 2.2 What that means concretely

| Dimension | Target | Anti-target |
|---|---|---|
| Colour | Deep, low-chroma, few hues | Bright, saturated, gradient-heavy |
| Density | Generous whitespace, one decision per screen | Dashboard-dense, everything at once |
| Typography | Clear hierarchy, 16 px floor, calm weights | Tiny text, heavy display weights |
| Imagery | Real cemeteries/gardens, daylight, no people in grief | Stock grief photography, candles, silhouettes |
| Motion | Barely noticeable | Animated, celebratory, attention-seeking |
| Copy voice | Plain Indonesian, direct, no euphemism-dodging | Corporate jargon, sales urgency, false cheer |
| Trust signals | Named source, last-updated timestamp, honest availability | Badges, testimonials-as-decoration, countdown timers |

**Imagery guidance is a constraint set, not only a constraint set — added 26 Aug 2026 (kamboja.co.id benchmark).** The row above states what to avoid; it does not yet say what to reach for when a photo *does* include people. kamboja.co.id's hero photography is deliberately warm, joyful family photography — grandparents with grandchildren, a father with his kids — rather than solemn funeral-industry imagery, and this validates the brand-refresh direction already in motion here ("lighter, younger, warmer," [`2026-08-21-brand-visual-refresh-design.md`](../superpowers/specs/2026-08-21-brand-visual-refresh-design.md)), demonstrated by the real Indonesian cemetery aerial photo already shipped in the Phase 2 homepage hero (§3.3c). The positive complement to the "no people in grief" constraint: **when photography includes people, prefer candid warmth and connection — family, community, everyday life — over solitary or somber framing.** This is guidance for future imagery choices, not a mandate to source new photography now; no photo currently in the codebase needs to change because of this addition. It still answers to every constraint in the table above (real, daylight, no grief) — it narrows *which* real, daylight, non-grief photography to prefer when people are present at all, it does not loosen anything.

### 2.3 DO / DON'T

**DO**

- Use `primary-600` for exactly one primary action per view. `Pemesanan Makam` is the primary CTA on the homepage ([IA §4](../product/information-architecture.md)).
- State availability honestly: `"Perlu konfirmasi"` when indicative ([booking Step 2](../product/booking-wizard-fields.md)). An indicative price is `neutral`, never `success`.
- Show the source and last-updated time on any fee or availability figure (renewal tariff, `G-RATE-01`).
- Pair every status colour with an icon **and** an Indonesian text label.
- Keep the customer-service escape hatch visible on every transactional screen — required by `AGENTS.md` and `screen-inventory.md` §D.
- Use `--mk-surface-warm` (Earth `primary-50`, ADR-0034 D6 — no longer Leaf) for trust/reassurance sections to soften long white pages.

**DON'T**

- ❌ **No urgency manufacturing.** No countdown timers, "hanya 2 tersisa", flashing, or red-dot pressure. A real quote expiry (`KEDALUWARSA`) is stated factually in `warning`, with the time and what happens next.
- ❌ **No celebration.** No confetti, no checkmark animation, no "Selamat!". Step 9 success is quiet: reference number, status, next action.
- ❌ **No colour-only status.** Fails WCAG 1.4.1 and fails the 8% of Indonesian men with CVD.
- ❌ **No green for `primary`** (see §1.2a).
- ❌ **No Leaf fills** — surface/accent only (§1.2b).
- ❌ **No pill buttons, no shadows above `--shadow-xl`, no gradient on any interactive surface.**
- ❌ **No stock photography of grieving people.** Cemetery, garden, facility, and product photography only.
- ❌ **No dark mode** until OQ-07 is resolved — it is absent from `screen-inventory.md`, so it has no required states and no test coverage.
- ❌ **Never display a private document thumbnail in a list view.** Signed URLs expire in 5 minutes and every access is audited; a list view would generate untracked bulk access.

---

## 3. Component primitives

Every component below specifies: **props → states → tokens → classes → a11y**. Class recipes assume the Tailwind wiring in §8.

Blade components live in `resources/views/components/`; the Livewire-facing wrappers in `app/View/Components/`. Filament panels consume the same tokens via §8.3 and should **not** re-implement these components.

### 3.1 Button — `<x-mk.button>`

**Props**

| Prop | Values | Default |
|---|---|---|
| `variant` | `primary` · `secondary` · `tertiary` · `ghost` · `danger` · `link` | `secondary` |
| `size` | `sm` · `md` · `lg` | `md` |
| `type` | `button` · `submit` | `button` |
| `icon` / `iconTrailing` | icon name | — |
| `full` | bool — full width | `false` (mobile CTAs: `true`) |
| `loading` | bool | `false` |
| `disabled` | bool | `false` |
| `href` | string — renders `<a>` with button appearance | — |

**Variants**

| Variant | Idle | Hover | Active | Use |
|---|---|---|---|---|
| `primary` | `bg-primary-600 text-white` | `bg-primary-700` | `bg-primary-800` | **One per view.** Lanjutkan, Bayar, Pesan Makam |
| `secondary` | `bg-white text-primary-700 border-primary-600` | `bg-primary-50` | `bg-primary-100` | Kembali, secondary action |
| `tertiary` | `bg-white text-neutral-700 border-neutral-450` | `bg-neutral-50` | `bg-neutral-100` | Neutral action, filters |
| `ghost` | `text-primary-700`, no border | `bg-primary-50` | `bg-primary-100` | Toolbar, table row action |
| `danger` | `bg-danger-600 text-white` | `bg-danger-700` | `bg-danger-800` | Destructive, confirmed only |
| `link` | `text-primary-600 underline` | `text-primary-700` | — | Inline in prose |

> **Fill colour stays on `primary-600`, not `primary-500`.** Sampled from the official logo (§1.2 OQ-12 note), `primary-500` measures only **4.73:1** for white text — a thin AA margin (the 4.5:1 floor with almost no headroom, versus `primary-600`'s comfortable 10.25:1). Do not reach for `500` as a button/badge fill; it is a mid-ramp swatch for surfaces and larger decorative use, not a text-bearing fill. See §7.1 for the measured ratio.

**Sizes** — `sm` `h-9` (36 px, **desktop admin tables only**) · `md` `h-11` (44 px, default) · `lg` `h-13` (52 px, primary CTA).

> `sm` is below the 44 px floor and is therefore **forbidden on any public/mobile surface**. It exists solely for dense Filament table row actions on pointer devices.

**States** — `idle` · `hover` · `focus-visible` · `active` · `loading` · `disabled`.

- `loading`: spinner replaces leading icon, label **stays visible**, `aria-busy="true"`, `disabled`. Never collapse the button width (layout shift at the payment moment is unacceptable).
- `disabled`: `bg-neutral-100 text-neutral-500 border-neutral-300 cursor-not-allowed`. **Never** use `opacity-50` — it silently breaks contrast.
- A disabled primary CTA must be accompanied by visible text explaining *why*, or it must not be disabled at all.

**Recipe**

```blade
{{-- resources/views/components/mk/button.blade.php --}}
@props([
  'variant' => 'secondary', 'size' => 'md', 'type' => 'button',
  'full' => false, 'loading' => false, 'icon' => null, 'href' => null,
])
@php
$base = 'inline-flex items-center justify-center gap-2 font-medium rounded-md
         transition-[color,background-color,border-color,box-shadow]
         duration-fast ease-standard select-none
         focus-visible:outline-none focus-visible:ring-2
         focus-visible:ring-primary-600 focus-visible:ring-offset-2
         disabled:cursor-not-allowed
         disabled:bg-neutral-100 disabled:text-neutral-500
         disabled:border-neutral-300';

$sizes = [
  'sm' => 'h-9 px-3 text-sm',
  'md' => 'h-11 px-4 text-base',
  'lg' => 'h-13 px-6 text-base',
];

$variants = [
  'primary'   => 'bg-primary-600 text-white border border-transparent hover:bg-primary-700 active:bg-primary-800',
  'secondary' => 'bg-white text-primary-700 border border-primary-600 hover:bg-primary-50 active:bg-primary-100',
  'tertiary'  => 'bg-white text-neutral-700 border border-neutral-450 hover:bg-neutral-50 active:bg-neutral-100',
  'ghost'     => 'bg-transparent text-primary-700 border border-transparent hover:bg-primary-50',
  'danger'    => 'bg-danger-600 text-white border border-transparent hover:bg-danger-700 active:bg-danger-800',
  'link'      => 'bg-transparent text-primary-600 underline underline-offset-2 hover:text-primary-700 h-auto p-0',
];
$classes = trim("$base {$sizes[$size]} {$variants[$variant]} " . ($full ? 'w-full' : ''));
@endphp

<{{ $href ? 'a' : 'button' }}
  {{ $href ? "href=$href" : "type=$type" }}
  {{ $attributes->merge(['class' => $classes]) }}
  @if($loading) aria-busy="true" disabled @endif>
  @if($loading)
    <x-mk.spinner class="size-5 shrink-0" aria-hidden="true" />
  @elseif($icon)
    <x-dynamic-component :component="'icon.'.$icon" class="size-5 shrink-0" aria-hidden="true" />
  @endif
  <span>{{ $slot }}</span>
</{{ $href ? 'a' : 'button' }}>
```

**a11y** — icon-only buttons require `aria-label`. Decorative icons get `aria-hidden="true"`. `focus-visible` ring is never removed. Loading state announces via `aria-busy`, not by swapping the label.

### 3.2 Input, Select, Textarea, Checkbox, Radio — `<x-mk.field>`

**Props:** `label` (required) · `name` · `type` · `hint` · `error` · `required` · `optional` · `prefix` · `suffix` · `autocomplete` · `inputmode` · `disabled` · `readonly`.

**States**

| State | Border | Extra |
|---|---|---|
| idle | `border-neutral-450` | 3.67:1 verified — **not** `neutral-300` |
| hover | `border-neutral-600` | — |
| focus | `border-primary-600` + `ring-2 ring-primary-600 ring-offset-1` | — |
| filled | as idle | — |
| error | `border-danger-600` | message below, `aria-invalid`, `aria-describedby` |
| disabled | `border-neutral-300 bg-neutral-100 text-neutral-500` | `--mk-text-disabled` = neutral-500, keeps 3.96:1 |
| readonly | `border-neutral-300 bg-neutral-50` | focusable and copyable |

**Rules**

- Height `h-11` minimum (44 px). Font size **exactly 16 px** — see §1.4.
- **Labels are always visible.** Placeholder-as-label is forbidden: it disappears on focus, fails 3.3.2, and is unusable on the deceased-data step where users re-check what they typed.
- Required marker: the word `wajib` or a `*` **with** a legend. Optional fields are labelled `(opsional)` — for a 4-step form, marking the smaller set is kinder.
- Error text sits **below** the field, `text-sm text-danger-700`, prefixed with an icon, and never replaces the hint — both can coexist.
- Indonesian input hints: `inputmode="numeric"` for NIK/amount, `inputmode="tel"` + `autocomplete="tel"` for mobile number, `autocomplete="email"`, `autocomplete="street-address"`.

```blade
<div class="flex flex-col gap-1.5">
  <label for="{{ $id }}" class="text-base font-medium text-neutral-800">
    {{ $label }}
    @if($optional)<span class="font-normal text-neutral-600">(opsional)</span>@endif
  </label>

  @if($hint)
    <p id="{{ $id }}-hint" class="text-sm text-neutral-600">{{ $hint }}</p>
  @endif

  <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}"
    @if($error) aria-invalid="true" @endif
    aria-describedby="{{ $hint ? "$id-hint" : '' }} {{ $error ? "$id-error" : '' }}"
    class="h-11 w-full rounded-md border bg-white px-4 text-base text-neutral-900
           placeholder:text-neutral-500
           transition-[border-color,box-shadow] duration-fast ease-standard
           focus:outline-none focus:ring-2 focus:ring-offset-1
           disabled:bg-neutral-100 disabled:text-neutral-500 disabled:border-neutral-300
           {{ $error
              ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
              : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}">

  @if($error)
    <p id="{{ $id }}-error" class="flex items-start gap-1.5 text-sm text-danger-700">
      <x-icon.alert-circle class="size-4 mt-0.5 shrink-0" aria-hidden="true" />
      <span>{{ $error }}</span>
    </p>
  @endif
</div>
```

Checkbox/radio: 20 px box inside a **44 px** clickable row; the whole row is the label target. `--radius-xs` for checkbox, `--radius-full` for radio.

### 3.3 Card — `<x-mk.card>`

**Props:** `as` (`div`|`a`|`article`) · `padding` (`none`|`sm`|`md`|`lg`) · `interactive` · `intent` · `media` · `header` · `footer`.

**Base:** `bg-white rounded-lg border border-neutral-200 shadow-sm`
**Padding:** mobile `p-4`, `md:p-6`.
**Interactive** (whole card is a link — TPU/TPS card, product card): add `hover:border-primary-300 hover:shadow-md focus-within:ring-2 focus-within:ring-primary-600 focus-within:ring-offset-2 transition-[border-color,box-shadow,background-color] duration-fast`. A card with no `intent` set also gets `hover:bg-primary-50` (added 19 Aug 2026, homepage visual refresh) — an intent card keeps its own `$intentSurfaces` background instead, never competing with it. Colour-only; no transform (§5's interaction table: hover Transform is `none`).

Interactive cards must contain **exactly one** focusable anchor; the standard technique (§3.3b) is to make the card's **root element** the `<a>` itself (`as="a"` + `interactive` + `:href`), not an overlay trick — never nest multiple links inside a clickable card (keyboard trap + confusing tab order).

**Variant — Cemetery card (Booking Step 2, PUB-011).** Must show: type badge (TPU/TPS), name, primary photo, address, facilities, price range **with source**, availability status, and the `"Perlu konfirmasi"` label when indicative. Availability uses §3.7 intent mapping; an indicative price is `neutral`, **never** `success`.

**Variant — Service/add-on row (Step 4, PUB-013).** Name, description, **fulfillment owner** (platform / operator / vendor — required by `service-catalog.md`), price, availability, quantity/variant control. An unavailable item stays visible only with a reason and an alternative; render it `bg-neutral-50 border-neutral-200` with a `neutral` badge — **not** `danger` (it is not an error).

### 3.3a Icon Medallion — `<x-mk.icon-medallion>` (added 19 Aug 2026, homepage visual refresh)

A decorative icon-or-numeral-on-a-tinted-tile figure — the "livelier" device the homepage visual
refresh introduced, used inside `<x-mk.card>`'s default slot (service cards, Cara Kerja steps,
trust points), never as a standalone status indicator.

**Props:** `icon` (`icon.*` component name; omit and use the default slot for a numeral instead)
· `tone` (`earth` | `leaf`, closed list) · `size` (`md` 44px default | `lg` 52px).

**Base:** `rounded-xl` (never `rounded-full` — §1.5 restricts that to avatars/stepper-dots/
progress-tracks). `earth` → `bg-primary-100 text-primary-800`. `leaf` → `bg-secondary-100
text-secondary-800` — a surface-tint usage already inside the Leaf cage (§1.2(b), §9.2 MUST-NOT
#7), not an exception to it. Both pairs are asserted in `docs/design/verify-contrast.py`.

Always `aria-hidden="true"` — decorative only, never a substitute for a real text label (same
rule `<x-mk.badge>`'s `dot` prop follows). Never placed adjacent to order/payment/availability
data, so it never carries a status reading despite `leaf` sitting near the `success` hue.

Icon-medallion is the sanctioned content device for §3.3b's nav-card pattern when the card has no
real photo — see §3.3b's "Content" rule.

### 3.3b Card as navigation — primary service grids

A **whole-card-is-the-link** pattern for building primary navigation and service-choice grids, reusing `<x-mk.card interactive as="a">` rather than inventing a new primitive.

**Pattern**

```blade
<x-mk.card
    as="a"
    interactive
    :href="route('service.show', ['slug' => $service->slug])"
    class="h-full touch-target"
>
    @if ($service->image_path)
        <x-slot:media>
            <img
                src="{{ asset($service->image_path) }}"
                alt=""
                class="h-40 w-full object-cover"
                loading="lazy"
            >
        </x-slot:media>
    @endif

    <div>
        <x-mk.badge intent="neutral">{{ $service->category }}</x-mk.badge>
    </div>

    <h2 class="text-lg font-semibold text-neutral-900">{{ $service->name }}</h2>

    <p class="text-base text-neutral-600">{{ $service->description }}</p>
</x-mk.card>
```

**Where it's used**

- **Existing:** `App\Livewire\Public\Marketplace\MarketplaceIndex`'s product grid (PUB-020, `resources/views/livewire/public/marketplace/index.blade.php`, the product grid `<ul aria-label="Daftar produk">`) — each product card links to its detail page via `as="a" :href="route('marketplace.product', ...)"`, using the real photo-`media` shape above.
- **Planned (Phase 2):** Homepage's four primary navigation items (`Pemesanan Makam`, `Layanan Pemakaman`, `Perpanjangan Makam`, `FAQ`) as an interactive card grid, matching the `kamboja.co.id` benchmark's "service cards" layout referenced in the brand refresh spec (§4.2). These have no product photo, so they use §3.3a's `<x-mk.icon-medallion>` in place of the `media` slot — icon-medallion is already documented as the device for exactly this "service cards" use case; a nav card is not required to have a photo.

**Usage rules**

- The **root element** is `<a>` (controlled by `as="a"` + `interactive` + `:href`). The whole card is one focusable link; never nest another `<a>` or `<button>` inside the card's default slot.
- **Content, with a photo** — `media` slot: full-bleed image clipped to the card's rounded corners. Use `alt=""` if decorative (the adjacent heading carries the text), or a real `alt` only if the image conveys unique information.
- **Content, without a photo** — use §3.3a's `<x-mk.icon-medallion>` in the default slot instead of a `media` slot (the homepage nav-card use case).
- Either way: badge, heading, description, or other text follows. Avoid multiple competing links; the card itself is the single navigation target.
- **Intent** — leave blank (`intent` prop omitted) so the `interactive` hover includes the `hover:bg-primary-50` tint, reinforcing the clickability. If an intent is needed (e.g. to show availability status), the `hover:bg-primary-50` suppresses automatically; see §3.3 `interactive` rules.

**Grid layout** — use the established card-grid pattern from §4.3:

```blade
<ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 md:gap-6" aria-label="Daftar layanan">
    @foreach ($services as $service)
        <li>
            <x-mk.card as="a" interactive :href="...">
                {{-- card content --}}
            </x-mk.card>
        </li>
    @endforeach
</ul>
```

### 3.3c Hero — `<x-mk.hero>` (added Task 4, brand visual refresh)

Pairs a real photo (§2.2: cemeteries/gardens, daylight, no people in grief) with the page's
primary heading and one CTA. Not yet wired into any real page in this phase — Phase 2 applies it
to the homepage.

**Props:** `image` (string) · `heading` (string, **required**) · `cta` (array,
`['label' => string, 'href' => string]`).

**Behaviour:** omitting `heading` throws `InvalidArgumentException` at render time —
`<x-mk.hero>` has no sensible fallback for a hero with no heading, so it fails loudly rather than
rendering a blank one.

**Base:** root `<div>` is `relative overflow-hidden rounded-lg`. When `image` is given, an `<img>`
(`h-64 md:h-96 w-full object-cover`) renders above a `bg-primary-50` content surface holding the
heading, an optional slot, and the CTA (`flex flex-col gap-4 p-6 md:p-8`).

**Heading typography:** `font-display text-4xl font-semibold tracking-tight text-neutral-900
lg:text-5xl`, matching §1.4's hero row (`text-4xl` mobile / `lg:text-5xl` desktop) verbatim. These
classes are restated explicitly in the component even though `app.css`'s base `h1` rule already
applies `font-display`, `tracking-tight`, and the strong text colour globally — the size scale is
the one thing `<x-mk.hero>` adds beyond the base layer; the rest is restated for the component's
own self-containment, not because of any conflict with other `<h1>`s in the codebase.

**CTA:** rendered as a single `<x-mk.button variant="primary" size="lg" :href="$cta['href']">` —
§2.3's DO of exactly one primary action per view. `<x-mk.hero>` does not support more than one CTA.

**A11y:** the image is deliberately decorative — `alt=""` always, matching this repo's existing
decorative-image convention (the heading already carries the information the image would). The
heading renders as a real `<h1>`, so it participates in the page's normal heading outline like any
other page.

### 3.3d Trust badge strip — `<x-mk.trust-badge-strip>` — ⚠️ **RESERVED, NOT BUILT** (documentation only, added 26 Aug 2026)

kamboja.co.id shows real trust signals prominently: review-platform badges, government/partner
logos, media coverage. This repository has **no real content for that yet** — no confirmed
partner relationships, no review-platform listing, no press mentions — and §2.3's existing DO/DON'T
already forbids "testimonials-as-decoration." **This subsection reserves the intended pattern and
its future component name so the slot is ready the day real content exists; it is documentation
only. Do not build the Blade component in this phase** — there is nothing real to pass it yet, and
an empty or placeholder-populated component is worse than no component (§2.3 DON'T, `AGENTS.md`
prohibition on fabricated claims).

**Intended shape, when built:** `<x-mk.trust-badge-strip>` following this repo's existing
`<x-mk.*>` naming convention (`resources/views/components/mk/`, alongside `hero.blade.php`,
`card.blade.php`, etc.) — a horizontal row of small logo/badge images (review platform, government
or industry certification, partner), each with real `alt` text naming the source (§2.2 "Trust
signals: named source" applies here too — a badge with no named source is exactly the
"badge-as-decoration" pattern §2.3 already forbids).

**Placement guidance:** near the homepage hero (directly below §4.5's hero section, before or
after "Cara kerja") or in the footer, matching where kamboja.co.id places its own trust row and
consistent with §4.5's normative homepage section order — inserting it would be a change to §4.5
and needs the same product-contract review any other homepage-order change needs, not a decision
this subsection makes unilaterally.

**Hard constraint — must not ship with fabricated content.** No placeholder logos, no invented
review-platform badges, no "as seen in" claims without a real citation. This mirrors §2.3's
existing "no testimonials-as-decoration" rule and `AGENTS.md`'s prohibition on implying a claim the
business cannot evidence. The component slot may be built only once real partner/certification/
review content exists to populate it — building it earlier, even with an intent to fill it in
later, invites exactly that fabrication risk.

### 3.3e Sticky comparison rail — `<x-mk.sticky-comparison-rail>` (added 26 Aug 2026)

**Ahead of content.** Built after a competitive read of `kamboja.co.id`'s pricing-tier page found
a strong pattern worth adopting: a sticky right-rail widget combining a condensed multi-tier price
comparison, the page's one primary CTA, a trust/review slot, and a compact related-links list — in
one persistent element, instead of spreading "compare plans," "convert," and "explore more" across
separate page sections. **Only that layout pattern is adopted.** Every visual treatment — colour,
shape, type, spacing — comes exclusively from this document's own tokens and the existing
`<x-mk.card>`/`<x-mk.button>`/`<x-mk.badge>` primitives; no pill-shaped buttons (§1.5 forbids them
site-wide) and no borrowed palette. **No real page consumes this component yet** — cemetery package
pricing and care-subscription tier pricing are both separate, in-progress workstreams that will
need this exact comparison UI once their tier data exists. Until then it ships as an isolated,
tested primitive (`tests/Feature/View/Components/MkStickyComparisonRailTest.php`), fixture data
only.

**Composition, not a new visual primitive.** The card chrome (border, radius, shadow, padding) is
delegated to `<x-mk.card padding="lg">` rather than reinvented — the same "extend, don't fork"
reasoning §3.6a documents for `filter-chip.blade.php`. This component only adds the `<aside>`
sticky-positioning wrapper and the tier/CTA/trust/links content structure inside the card.

**Props**

| Prop | Values | Default |
|---|---|---|
| `heading` | string \| null | `null` — no default marketing copy is invented; omit to render no heading |
| `label` | string | `'Perbandingan paket'` — accessible name for the `<aside>` landmark when `heading` is absent |
| `tiers` | array of `['label', 'price', 'priceSource', 'indicative', 'description']` | `[]` |
| `cta` | array `['label' => string, 'href' => string]`, **required** | — |
| `links` | array of `['label' => string, 'href' => string]` | `[]` |

**Tier price — the honest-availability convention, reused verbatim.** `price` is a
**pre-formatted** string (formatting happens upstream, e.g. `CemeteryPresenter::priceRange()`,
never inside the view). A `null` price renders "Belum tersedia" — the same honest-empty-state
reasoning `CemeteryPresenter`'s own doc block states: *"showing nothing is honest; showing a number
with an invented source would not be."* No fixture or caller may substitute a fabricated example
price for a missing one. When a price is present and `priceSource` is given, it renders as
"Sumber: {priceSource}" per §2.3's "show the source ... on any fee figure." When `indicative` is
`true`, the tier renders the same `<x-mk.badge intent="neutral" icon="clock">Perlu konfirmasi</x-mk.badge>`
the cemetery directory already uses (`resources/views/livewire/public/directory/detail.blade.php`)
— `neutral`, never `success` (§2.3: an indicative price is never styled as a success).

**CTA — exactly one primary action.** Rendered as a single
`<x-mk.button variant="primary" size="lg" full :href="$cta['href']">`, identical in spirit to
`<x-mk.hero>`'s CTA contract (§3.3c) and §2.3's "one primary action per view." Omitting `cta`
throws `InvalidArgumentException` at render time — same fail-loudly convention `<x-mk.hero>` uses
for a missing `heading`.

**Trust slot — ties into §3.3d's reserved pattern, no invented content.** An optional named
`trust` slot exists for a trust/review-badge component to plug into this rail. §3.3d above (landed
on trunk after this component's own authoring began) reserves exactly that future component,
`<x-mk.trust-badge-strip>`, as **documentation only — not yet built**, because no real
partner/review/certification content exists to populate it. This slot is the documented place a
future `<x-slot:trust><x-mk.trust-badge-strip>...</x-mk.trust-badge-strip></x-slot:trust>` usage
will go once §3.3d's component actually ships; until then it renders only what a caller supplies,
with **no built-in fallback content** — this component does not build `<x-mk.trust-badge-strip>`
itself, and does not invent placeholder trust content, per §3.3d's own hard constraint.

**Related links.** Each entry renders as `<x-mk.button variant="link">` — §3.1's existing "link:
inline in prose" variant — rather than inventing a new link-list primitive.

**Responsive behaviour.** Desktop: sticky positioning reuses §4.3's own wizard "form + sticky
summary" example verbatim — `md:sticky md:top-24 md:self-start` — rather than inventing a new
breakpoint or offset. Below `md` the `<aside>` is a plain, non-sticky block in normal document
flow, matching `<x-mk.table>`'s own `md:`-gated mobile-first collapse (§3.5). No new `--mk-z-*`
layer is introduced: like the wizard aside it mirrors, this is a sticky element positioned *within*
the page's normal content column, not a viewport-pinned bar, so none of §1.5's z-index layers apply.

### 3.4 Modal and Bottom Sheet — `<x-mk.modal>`

**Mobile-first rule: below `md`, a modal renders as a bottom sheet.** Centred dialogs on a 360 px viewport push content under the keyboard.

**Props:** `size` (`sm`|`md`|`lg`) · `title` (required) · `dismissible` (default `true`) · `intent` · `wire:model`.

| Part | Classes |
|---|---|
| Backdrop | `fixed inset-0 bg-[var(--mk-surface-overlay)] z-backdrop` |
| Sheet (`<md`) | `fixed inset-x-0 bottom-0 z-modal rounded-t-xl bg-white pb-[var(--mk-safe-bottom)] max-h-[var(--mk-modal-sheet-max-height)] overflow-y-auto` |
| Dialog (`md+`) | `md:relative md:inset-auto md:mx-auto md:my-16 md:max-w-lg md:rounded-xl md:shadow-xl` |
| Header | `sticky top-0 bg-white border-b border-neutral-200 px-4 py-4 flex items-start gap-3` |
| Footer | `sticky bottom-0 bg-white border-t border-neutral-200 px-4 py-4 flex flex-col-reverse gap-3 md:flex-row md:justify-end` |

Footer is `flex-col-reverse` on mobile so the **primary action sits at the bottom**, in thumb reach, and appears first in DOM order for screen readers.

**a11y** — `role="dialog"` `aria-modal="true"` `aria-labelledby` pointing at the title. Focus moves to the dialog on open, is trapped inside, and returns to the trigger on close. `Esc` closes when `dismissible`. Background scroll locked. Use `dvh` not `vh` (mobile browser chrome).

**Confirmation modals for irreversible actions** (cancel order, reject vendor order, revoke certificate) must: use `danger` for the confirm button, state the consequence in the body, require a typed reason where `order-lifecycle.md` mandates one (`DITOLAK` reason is mandatory), and **never** default-focus the destructive button.

### 3.5 Table — `<x-mk.table>`

**Mobile-first rule: below `md`, tables become stacked cards.** Horizontal scrolling on a transaction list is a usability failure. One record per card, label–value pairs, primary action at the bottom.

Desktop (`md+`): `w-full text-sm` · header `bg-neutral-50 text-xs font-semibold uppercase tracking-wide text-neutral-600 text-left` · rows `border-b border-neutral-200` · hover `bg-[var(--mk-table-hover)]` · optional zebra `--mk-table-stripe`.

- Numeric/currency columns: `text-right tabular-nums font-mono`.
- Sortable headers are `<button>` inside `<th>` with `aria-sort`.
- Row selection: checkbox in a 44 px target; a sticky bulk-action bar at `z-sticky-cta`.
- Every table needs `<caption class="sr-only">` describing its content.
- Sticky header on long lists: `sticky top-0 z-raised`.

**Bulk export is a privileged action** requiring recent re-authentication (`AGENTS.md`). Render that button as `secondary`, never `primary`, and never place it adjacent to a benign action.

### 3.6 Badge / Tag — `<x-mk.badge>`

**Props:** `intent` (`neutral`|`info`|`pending`|`success`|`danger`|`urgent`) · `size` (`sm`|`md`) · `icon` · `dot`.

**Base:** `inline-flex items-center gap-1 rounded-sm px-2 py-0.5 text-xs font-medium border`
**Colours:** `bg-[var(--mk-intent-{intent}-bg)] text-[var(--mk-intent-{intent}-fg)] border-[var(--mk-intent-{intent}-border)]`

Every intent's `800`-on-`100` pairing is verified ≥ 7.25:1 (§7.1).

**Mandatory:** a badge always carries **text**, and carries an **icon** whenever it communicates status. Colour alone never conveys state (WCAG 1.4.1). Never abbreviate a canonical status enum in the badge label.

**`intent` is a closed vocabulary — an unsupported value now throws, not silently falls back to `neutral`.** Found 8 Aug 2026: `$intents[$intent] ?? $intents['neutral']` let a typo'd or invented intent render as a plausible-looking `neutral` badge with no error — on a status badge, a wrong status shown with nothing to catch it. Fixed to `throw new InvalidArgumentException(...)`, matching this codebase's `assertKnown()` convention for every other closed list.

### 3.6a Filter chip — `<x-mk.filter-chip>`

A **selection** control (which filter is active in a chip group), not a status marker — deliberately not a `<x-mk.badge>` intent. Added 8 Aug 2026 after two Sprint 4 batches (cemetery directory, marketplace) independently hand-wrote the same badge-shaped active/inactive chip recipe `public-faq`'s own `tasks.md` established first (`primary-100`/`primary-800` — already an asserted WCAG AA pair, §7.1, no new token or contrast work needed), because `<x-mk.badge>` has no `primary` intent and `$attributes->merge()` only appends onto its already-complete intent classes rather than replacing them. Three independent call sites reproducing one recipe is where §9.2 MUST #2 ("extend, don't fork") calls for a real primitive.

**Props:** `href` · `active` (bool, default `false`).

**Selection must not be colour-only (WCAG 1.4.1).** The active state adds a `icon.check` tick — not just a darker colour pair — plus `aria-current="page"` for assistive tech. This closes a real gap: `public-faq`'s existing hand-written chips signalled the active state by hue alone, with no non-colour cue on the "Semua Kategori" reset chip specifically (its page heading stays static regardless of which chip is active, so the redundant-heading argument that covers its per-category chips does not cover it).

**Structure:** one wrapping `<a>` (touch-target, focus ring), one inner `<span>` carrying the intent-shaped classes — same two-layer shape the hand-written recipe already used, formalised into a primitive rather than copied a fourth time.

### 3.7 Status → visual intent mapping (**normative**)

Components must **not** switch on enum strings. Resolve status → intent in one place (`app/Support/Design/StatusIntent.php`) and pass the intent down. Enums are canonical in [`order-lifecycle.md`](../domain/order-lifecycle.md) and [`marketplace-catalog.md`](../product/marketplace-catalog.md) — this table maps them, it does not define them.

**Order lifecycle**

| Status | Intent | Icon | Rationale |
|---|---|---|---|
| `MASUK` | `neutral` | inbox | Received, nothing decided |
| `DIVERIFIKASI` | `info` | shield-check | Progressing |
| `MENUNGGU_KETERSEDIAAN` | `pending` | clock | Waiting on operator |
| `PENAWARAN_TERKIRIM` | `info` | document-text | Action available to customer |
| `DISETUJUI_PEMESAN` | `info` | check-circle | Progressing, not paid |
| `MENUNGGU_PEMBAYARAN` | `pending` | clock | Awaiting user action |
| `MENUNGGU_VERIFIKASI_PEMBAYARAN` | `pending` | clock | Manual fallback. **Never `success`** |
| `DIBAYAR` | `success` | banknote | Money confirmed |
| `DIPROSES` | `info` | cog | Fulfilment underway |
| `SELESAI` | `success` | check-badge | Terminal success |
| `DITOLAK` | `danger` | x-circle | Terminal. **Reason mandatory** |
| `DIBATALKAN` | `neutral` | slash | Terminal, not an error |
| `KEDALUWARSA` | `neutral` | clock-x | Terminal, expiry is factual not alarming |

**Vendor processing**

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

**Marketplace payment** (`PaymentState`, added 14 Aug 2026 with the marketplace-checkout lane)

| Status | Intent | Icon |
|---|---|---|
| `BELUM_DIBAYAR` | `pending` | clock |
| `MENUNGGU_VERIFIKASI` | `pending` | clock |
| `DIBAYAR` | `success` | banknote |
| `GAGAL` | `danger` | x-circle |
| `DIKEMBALIKAN` | `neutral` | clock-x |

**Plot state** (`PlotState`, added 28 Aug 2026 with the plot availability dashboard)

Status values are the lowercase stored values of `app/Domain/PlotInventory/PlotState.php` — `grave_plots.plot_state` never holds an uppercase value.

| Status | Intent | Icon | Label | Rationale |
|---|---|---|---|---|
| `available` | `success` | check-circle | Tersedia | Reservable now |
| `reserved` | `pending` | clock | Dipesan | Claimed by an active reservation, not yet a burial |
| `occupied` | `danger` | slash | Terisi | A burial has taken place. `danger` reads "not bookable", the `slash` icon reads "terminal and factual, not an error" |
| `maintenance` | `info` | cog | Perawatan | Operator-declared unavailable, work underway |

**Cemetery package availability** (`CemeteryPackageAvailabilityStatus`, added 28 Aug 2026 with the plot availability dashboard)

| Status | Intent | Icon | Label | Rationale |
|---|---|---|---|---|
| `AVAILABLE` | `success` | check-circle | Tersedia | Open for enquiry |
| `LIMITED` | `pending` | alert-circle | Terbatas | Capacity constrained |
| `UNAVAILABLE` | `danger` | slash | Penuh | No capacity at this class |

> **Every value in the cemetery-package table is indicative, never a guarantee.** The owning cemetery's `cemetery_capability_profiles.availability_mode` is the single source of truth for whether any availability claim is a guarantee, and it never is under the safe `INDICATIVE` default (see `CemeteryPackageAvailabilityStatus`'s own doc block). `Tersedia` therefore means "open for enquiry", never "reserved for you".

**Plot reservation state** (`PlotReservationState`, added 29 Aug 2026 — final-review fix wave, finding I-2)

Status values are the lowercase stored values of `app/Domain/PlotReservation/PlotReservationState.php` — `plot_reservations.state` never holds an uppercase value. A separate family from "Plot state" above: a reservation's own lifecycle and the plot's operational state are two different questions.

| Status | Intent | Icon | Label | Rationale |
|---|---|---|---|---|
| `held` | `pending` | clock | Ditahan | Active claim, not yet confirmed |
| `confirmed` | `success` | check-circle | Dikonfirmasi | Active claim, confirmed |
| `released` | `neutral` | slash | Dilepaskan | Terminal, not an error |
| `expired` | `neutral` | clock-x | Kedaluwarsa | Terminal, expiry is factual not alarming |
| `converted` | `neutral` | slash | Dikonversi | Terminal, not an error — the draft hold's own chain closes here when its plot claim moves to a new order-anchored reservation |

> **Plot state and package availability are two granularities, not two spellings of one thing.** A granular-tier cemetery answers availability from `grave_plots.plot_state`; an aggregate-tier cemetery answers it from `cemetery_packages.availability_status`. They are separate `StatusIntent` families and must never be merged.

> `PaymentState` and `VendorProcessingStatus` are deliberately separate vocabularies. A paid order is never a completed one, and the two always render as two distinct indicators (PUB-024), never one merged "done" badge.

> **`DIBAYAR` ≠ `SELESAI`.** `marketplace-catalog.md` and `AGENTS.md` both require this: "Paid does not mean completed." Payment and fulfilment are separate states and must be shown as two distinct indicators, never merged into one "done" badge.

### 3.8 Alert / Banner — `<x-mk.alert>`

**Props:** `intent` · `title` · `dismissible` · `icon` · `action` (slot) · `live` (`polite`|`assertive`|`off`).

**Base:** `flex items-start gap-3 rounded-lg border-l-4 p-4`
Left border uses the intent's `600`; background the `50`; text the `800`.

| Intent | Use |
|---|---|
| `info` | **Gated fallback mode banners** (§6.9), neutral system notices |
| `pending` | Quote expiring, scan in progress, awaiting verification |
| `success` | Payment confirmed, draft saved, document accepted |
| `danger` | Validation summary, payment failed, file rejected, authorization failure |
| `urgent` | Urgent availability window — always with explicit availability text |

**a11y** — errors appearing after submit: `role="alert"` (assertive). Ambient/pre-existing notices: `role="status"` `aria-live="polite"`. A dismissible alert's close button is a 44 px target with `aria-label="Tutup"`. Never auto-dismiss an error.

### 3.9 Stepper — `<x-mk.stepper>` (booking Steps 1–4)

`booking-wizard-fields.md` requires progress shown as **1–4**, back navigation that preserves data, server-side validation, and no skipping upstream decisions. Labels are canonical — **do not reword**:

```
1 Cari & Pilih · 2 Detail Pemesanan · 3 Pembayaran · 4 Konfirmasi
```

**Mobile (`< md`) — compact.** The compact mobile layout below still applies for any journey with more steps than fit legibly at 360px — not specific to booking's dot count.

```
┌──────────────────────────────────────────┐
│  Langkah 2 dari 4                        │  text-sm text-neutral-600
│  Detail Pemesanan                        │  text-xl font-semibold
│  ████████████████░░░░░░░░░░░░░░░░        │  h-1 rounded-full
└──────────────────────────────────────────┘
```

- Track `--mk-progress-track`, fill `--mk-progress-fill`, `h-1`.
- Wrapper: `role="group"` `aria-label="Progres pemesanan"`.
- Progress bar: `role="progressbar" aria-valuenow="2" aria-valuemin="1" aria-valuemax="4"`.
- Sticky at `z-sticky-cta` under the header; collapses to the numeric line on scroll.

**Desktop (`md+`) — full rail.** Horizontal dots + labels.

| Step state | Dot | Label | Interaction |
|---|---|---|---|
| `complete` | `bg-primary-600 text-white` + check | `text-neutral-700` | **Clickable** — back nav preserves data |
| `current` | `bg-white border-2 border-primary-600 text-primary-700` | `text-neutral-900 font-semibold` | `aria-current="step"` |
| `upcoming` | `bg-neutral-100 border border-neutral-300 text-neutral-500` | `text-neutral-500` | Not clickable, `aria-disabled="true"` |
| `error` | `bg-danger-50 border-2 border-danger-600 text-danger-700` | `text-danger-700` | Clickable |

Dot size `--mk-stepper-dot` (28 px) but the **clickable area is 44 px**. Connector `--mk-stepper-track` (2 px): `primary-600` behind completed steps, `neutral-300` ahead.

**Autosave affordance** — `booking-wizard-fields.md` mandates autosave every 10 s while dirty. Show a quiet inline indicator near the stepper, **not** a toast:

- saving → `text-sm text-neutral-600` + spinner, "Menyimpan…"
- saved → `text-sm text-success-700` + check, "Tersimpan 14:32"
- failed → `text-sm text-danger-700` + icon, "Gagal menyimpan. Coba lagi" + retry button

Wrap it in `aria-live="polite"` so it is announced without stealing focus. **Never block the form on autosave.**

**Urgent / Pre-Need branching** — internal workflow may differ, but the stepper still reads 1–4 and the user always reaches an explicit outcome (`booking-wizard-fields.md` §Branching). The stepper is a **presentation** contract; do not renumber it per branch.

**Props**

| Prop | Default | Meaning |
|---|---|---|
| `step` | `1` | Current step, clamped to `1..count($labels)` |
| `errorSteps` | `[]` | Step numbers currently in the `error` state |
| `stepMethod` | `'goToStep'` | Livewire method invoked as `stepMethod(n)` by a clickable dot |
| `labels` | **the four booking labels above** | Ordered step labels for the journey being rendered |

**The four-step default is normative as a fallback contract, though booking's own call site no longer relies on it.** The component's default `labels` value still renders the canonical booking wording if a caller omits the prop, and that remains the safety net against an accidental reword for any future caller. In practice, booking's own Blade view (`resources/views/livewire/public/booking/wizard.blade.php`) now explicitly passes `:labels="\App\Domain\Booking\BookingWizardScreen::labels()"` rather than omitting the prop — so the default is no longer what booking is actually observed to render, but it is still the contract quoted at the top of this section, and omitting `labels` from any caller MUST still render exactly these four, in that order, with that wording. This is a component-contract change, not a token change, so [§9.4](#94-changing-a-token) does not apply and **no ADR is required**; §9.4 governs `tokens.css` only.

**`labels` is for a different journey, never for re-labelling booking.** It exists because the renewal journey (`.kiro/specs/renewal-and-grave-registry` AC1) is **three** visible steps — Cari Makam · Biaya & Bayar · Konfirmasi — and its `tasks.md` requires this same primitive rather than a second stepper. Passing `labels` from a booking surface to rename, reorder, hide, or renumber a booking step is forbidden by `AGENTS.md` (§Mandatory MVP UX, "Booking exposes Steps 1–4 exactly as documented") and by [§9.2](#92-rules-for-developers-and-ai-agents-enforceable) MUST NOT 9 — **except** that `BookingWizardScreen`/`RenewalWizardScreen`'s SCREEN-vocabulary labels (passed via `labels` from each journey's own screens, as booking's wizard view now does) are not an ad hoc rename of a step; they are the documented, deliberate step-count reduction this section's heading and canonical block already reflect. Urgent / Pre-Need branches keep reading 1–4; they do not supply their own divergent `labels`.

Every count is derived from the supplied array, never from a literal 4 — the total, the mobile `Langkah N dari M` line, `aria-valuemin`/`aria-valuemax`, the `step` clamp, and the progress-bar percentage. A supplied array may be a 0-indexed list or an already-1-indexed map; it is re-keyed to a contiguous `1..N` sequence either way. `role="group"` and `aria-label="Progres pemesanan"` are merge defaults, so a non-booking journey supplies its own accessible group name alongside its own `labels`.

### 3.10 Header — `<x-mk.header>`

Per [IA §2](../product/information-architecture.md), labels are identical on mobile and desktop: `Pemesanan Makam` · `Layanan Pemakaman` · `Perpanjangan Makam` · `FAQ` · `Masuk/Akun` · `Bantuan`.

**Mobile (`< lg`)** — `h-[var(--mk-header-h)]` (56 px), `sticky top-0 z-header bg-white border-b border-neutral-200`, `pt-[var(--mk-safe-top)]`.

```
┌────────────────────────────────────────────────┐
│  [☰]   Makam.co.id            [ Bantuan ]      │
└────────────────────────────────────────────────┘
```

The **persistent Bantuan action is mandatory** (IA §2) — it is never collapsed into the hamburger.

**Desktop (`lg+`)** — 72 px, full horizontal nav. Active item: `text-primary-700 font-medium` + 2 px `primary-600` bottom border + `aria-current="page"`.

**Invariants (IA §4)** — all four main menu items are visible without login; `Pemesanan Makam` is the primary CTA; `Urgent` gets visual priority without a service claim while `G-OPS-01` is closed.

A **skip link** (`z-skiplink`) is the first focusable element: visually hidden until focused, then `bg-primary-600 text-white` pinned top-left, targeting `#main`.

### 3.11 Bottom navigation — ⚠️ **PROPOSED, NOT APPROVED**

> **This component is not in the approved IA.** [IA §2](../product/information-architecture.md) specifies mobile navigation as *logo + hamburger + persistent Bantuan*. `AGENTS.md` forbids inventing alternate navigation or labels without product change approval.
>
> **Default implementation is the IA-compliant header in §3.10.** The bottom nav below is a design proposal pending approval — tracked as **OQ-04**. Do not ship it without a product decision recorded in `mvp-scope.md` or an ADR.

If approved, it must use the four canonical labels unchanged and follow: `fixed inset-x-0 bottom-0 z-bottomnav h-[var(--mk-bottomnav-total)] bg-white border-t border-neutral-200 pb-[var(--mk-safe-bottom)]`; 4 items, each a 44 px minimum target with icon + label (`text-2xs`); active `text-primary-700` with icon fill; `<nav aria-label="Navigasi utama">` + `aria-current="page"`. Any sticky wizard CTA must sit **above** it (`z-sticky-cta` < `z-bottomnav`) — content is padded by `--mk-bottomnav-total`, never overlapped.

---

## 4. Layout and grid

### 4.1 Page shell

```
┌─ Skip link (z-skiplink, visible on focus) ─────────────────┐
├─ Header (z-header, sticky, 56px / lg:72px) ────────────────┤
│  ┌─ Mode banner (§6.9) — only when a gate is closed ─────┐ │
│  ├─ main#main ───────────────────────────────────────────┤ │
│  │    Container: max-w-content, px-4 md:px-6 lg:px-8     │ │
│  │    Section gap: 40px mobile / 64px lg                 │ │
│  └───────────────────────────────────────────────────────┘ │
├─ Footer (surface-inverse: primary-900) ────────────────────┤
└─ Toast region (z-toast, aria-live) ────────────────────────┘
```

### 4.2 Containers

| Token | Width | Use |
|---|---|---|
| `--container-prose` | 640 px | FAQ article, legal, long copy |
| `--container-form` | 768 px | **Booking wizard, checkout, renewal** |
| `--container-content` | 1280 px | Page shell, listings, dashboards |

Gutters: `px-4` (16) mobile → `md:px-6` (24) → `lg:px-8` (32).

The booking wizard is capped at `--container-form` **even on wide desktop**. A 1280 px-wide form encourages horizontal eye travel and raises error rates on the deceased-data step.

### 4.3 Grid progression

| Content | base (320) | `sm` 640 | `md` 768 | `lg` 1024 | `xl` 1280 |
|---|---|---|---|---|---|
| Homepage service cards (4) | 1 | 2 | 2 | 4 | 4 |
| Cemetery list (Step 2) | 1 | 1 | 2 | 2 | 3 |
| Marketplace products | 1 | 2 | 2 | 3 | 4 |
| Wizard form | 1 | 1 | 1 + sidebar | 1 + sidebar | 1 + sidebar |
| Admin/vendor table | cards | cards | table | table | table |
| FAQ list | 1 | 1 | sidebar + list | sidebar + list | sidebar + list |

Grid gap: `gap-4` mobile → `md:gap-6`.

```html
<!-- Homepage service cards -->
<ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 md:gap-6">
<!-- Wizard: form + sticky summary -->
<div class="grid grid-cols-1 gap-6 md:grid-cols-[minmax(0,1fr)_20rem]">
  <div><!-- steps --></div>
  <aside class="md:sticky md:top-24 md:self-start"><!-- ringkasan --></aside>
</div>
```

### 4.4 Vertical rhythm

`--mk-section-gap` 40 px mobile / `--mk-section-gap-lg` 64 px desktop between page sections. `--mk-stack-gap` 16 px inside a card. `--mk-field-gap` 20 px between form fields. Heading to its content: 8 px. Content to next heading: 32 px. Proximity carries the grouping — do not reach for divider lines.

### 4.5 Homepage section order (**normative** — IA §3)

1. Header/navigation
2. Hero + value proposition + CTA `Pesan Makam`
3. **Four service cards, in stakeholder order** — Pemesanan Makam · Layanan Pemakaman · Perpanjangan Makam · FAQ
4. Cara kerja (brief)
5. Featured/available TPU–TPS *(only when data exists)*
6. Trust/safety information — use `--mk-surface-warm`
7. FAQ highlights
8. Customer-service CTA
9. Footer — privacy, terms, contact

**This order is a product contract, not a design preference.** Do not reorder, and do not drop §5 silently — when there is no data, use the empty-state pattern (§6.2).

### 4.6 Front-end weight budget

Derived from [`performance-and-capacity.md`](../operations/performance-and-capacity.md) §3 (homepage server p95 ≤ 500 ms; wizard read/save p95 ≤ 1000 ms) and the 2 vCPU/4 GB non-production host. These are **design-side guardrails**, not measured results (§12).

| Budget | Target |
|---|---|
| CSS shipped (gzip) | ≤ 45 KB |
| JS shipped, public pages (gzip) | ≤ 60 KB (Livewire + Alpine + app) |
| Font payload, initial route | ≤ 60 KB (Inter variable, latin subset, `woff2`, `font-display: swap`) |
| `--font-display` serif | Loaded only on hero + document routes |
| Largest hero image | ≤ 120 KB, `AVIF`/`WebP`, explicit `width`/`height` |
| Icons | Inline SVG sprite, tree-shaken. **No icon font.** |
| CLS | < 0.1 — every image and skeleton reserves its box |
| Third-party requests on public pages | **0** (no CDN fonts, no external analytics on document routes) |

Alpine usage is limited to isolated modules per `overview.md` §3 — no global Alpine store for domain state.

---

## 5. Motion

Covered in §1.6 (tokens). Design rules:

| Interaction | Duration | Easing | Transform |
|---|---|---|---|
| Hover/focus colour | `fast` 120 ms | `standard` | none |
| Dropdown / popover open | `base` 180 ms | `decelerate` | opacity + `translateY(-4px)` |
| Dropdown close | `fast` 120 ms | `accelerate` | opacity |
| Modal open (desktop) | `slow` 260 ms | `decelerate` | opacity + `scale(0.98→1)` |
| Bottom sheet open | `slow` 260 ms | `decelerate` | `translateY(100%→0)` |
| Wizard step change | `slow` 260 ms | `emphasized` | opacity only — **no horizontal slide** |
| Skeleton → content | `slower` 400 ms | `standard` | crossfade |
| Toast enter | `base` 180 ms | `decelerate` | opacity + `translateY(8px)` |
| Accordion | `base` 180 ms | `standard` | `grid-template-rows` |

**No horizontal slide between wizard steps.** It implies a filmstrip the user can swipe, invites accidental back-navigation on mobile, and costs layout work on a 2 vCPU host. Crossfade + scroll-to-top is calmer and cheaper.

`prefers-reduced-motion` is handled globally in `tokens.css` §3.

---

## 6. State patterns (**mandatory**)

`AGENTS.md` requires every transactional screen to have loading, empty, error, pending, success, and support states. [`screen-inventory.md`](../product/screen-inventory.md) §D expands this to ten. **All ten are required. A screen missing one is incomplete, not "shipped".**

| # | State | Pattern | §  |
|---|---|---|---|
| 1 | loading | Skeleton (structural) or inline spinner (action) | 6.1 |
| 2 | empty | Icon + cause + next action | 6.2 |
| 3 | validation error | Inline per field + summary alert | 6.3 |
| 4 | authorization failure | Explanatory page, never a raw 403 | 6.4 |
| 5 | provider unavailable | Fallback path + support | 6.5 |
| 6 | duplicate / retry-safe | Idempotent confirmation, no double charge | 6.6 |
| 7 | pending | Explicit wait + what happens next + ETA if known | 6.7 |
| 8 | success | Quiet confirmation + reference + next action | 6.8 |
| 9 | support escape hatch | Persistent, on every transactional screen | 6.10 |
| 10 | responsive mobile | Every state designed at 320 px first | — |

### 6.1 Loading

**Skeleton** for structural/first loads. Mirrors the real layout to keep CLS < 0.1: `bg-[var(--mk-skeleton-base)] rounded-md animate-pulse`. Reserve exact heights. Never a full-page spinner on a route that has known structure.

**Inline** for user-triggered actions: the button enters `loading` (§3.1) — label stays, width stays.

**Livewire:** `wire:loading.delay` (default 200 ms) prevents flicker on fast responses. Always pair with `wire:target` so a spinner attaches to the action that caused it, not the whole page.

```blade
<div wire:loading.delay wire:target="cariMakam" class="space-y-3" aria-busy="true">
  <div class="h-20 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
  <div class="h-20 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
  <span class="sr-only">Memuat hasil pencarian…</span>
</div>
```

Every skeleton carries an `sr-only` announcement; a screen-reader user hears nothing from a pulsing box.

### 6.2 Empty

Three parts, always: **what is empty · why · what to do next.** Never a bare "Tidak ada data".

| Screen | Message | Next action |
|---|---|---|
| Grave search, no result (PUB-031) | "Data makam tidak ditemukan." + explain the registry may be incomplete | `Input manual` / `Hubungi bantuan` — required by `mvp-scope.md` §4 "honest empty state" |
| Cemetery list, no result (PUB-011) | "Belum ada TPU/TPS yang cocok dengan filter." | `Reset filter` |
| Empty category (PUB-020) | "Belum ada produk di kategori ini." | `Lihat kategori lain` |
| No draft (`/akun/draft`) | "Belum ada draft pemesanan." | `Mulai pemesanan` |
| Featured cemeteries absent (homepage §5) | Hide the section entirely — do not render an empty shell | — |

Layout: `flex flex-col items-center gap-3 py-12 text-center`, icon `size-12 text-neutral-400`, title `text-lg font-semibold text-neutral-800`, body `text-base text-neutral-600 max-w-prose`, then a `secondary` button.

**Privacy-limited empty (PUB-031)** is distinct from "not found": when `G-DATA-01` restricts the field projection, say so explicitly rather than implying the record does not exist.

### 6.3 Validation error

- **Inline per field** — the primary mechanism (§3.2), `aria-invalid` + `aria-describedby`.
- **Summary alert** at the top of the form on submit — `danger`, `role="alert"`, listing each error as an anchor to its field.
- Focus moves to the summary (not the first field) so the user hears the count before being dropped into an input.
- Never clear entered data on a validation failure. Never lose an uploaded file reference.
- Server-side is authoritative (`booking-wizard-fields.md`). Client-side validation is a convenience layer only — it must never gate submission on its own.

### 6.4 Authorization failure

`information-architecture.md` §4: *"Route unavailable karena gate harus memberi explanatory page, bukan 404 generik."*

Full-page pattern: what happened, why (in plain Indonesian, without leaking authorization internals), what the user can do, and a support link. Distinguish three cases:

| Case | Message shape |
|---|---|
| Not signed in | "Masuk untuk melanjutkan" + deep link preserved (IA §4: return to origin after auth) |
| Signed in, no access | "Anda tidak memiliki akses ke halaman ini." + support |
| Gate closed | Explain the capability is not yet available + the documented fallback path |

**Never** render a raw 403/404 for a gated route, and never expose which record exists via differing error text.

### 6.5 Provider unavailable

Payment provider, WhatsApp, malware scanner, or grave registry down. Pattern: `danger` or `pending` alert (per severity) + **the fallback path** + support.

- Payment provider down at Step 8 → offer manual coordination if `PaymentMode` permits, otherwise a pending state with a callback promise. **Never a dead end on the payment step.**
- Malware scanner unavailable → the pipeline is fail-closed (`AGENTS.md`), so the file shows `pending`, not `accepted`. Say the scan is queued, not that the upload succeeded.
- **Never** show a technical error string, stack trace, provider name, or correlation ID to a public user. The correlation ID goes to logs; the user gets a support reference.

### 6.6 Duplicate / retry-safe

Webhooks are idempotent and replay-protected; saves are idempotent and versioned. The UI must match:

- Re-submitting a paid order shows the **same** confirmation, not a second order.
- A double-tapped CTA is blocked by the `loading` state, not by a client-side flag alone.
- Refreshing Step 9 re-renders the same reference.
- One reminder per grave/window, one invoice per cycle, one renewal settlement per period (`AGENTS.md`) — the UI must never offer an action that would create a duplicate.

### 6.7 Pending — including document quarantine

Pending is the **most common state in this product** and the easiest to get wrong. Rule: state what is being waited on, who acts next, and the expected window if known. Use `pending` intent. **Never style a pending state as success.**

| Situation | Message | Intent |
|---|---|---|
| `MENUNGGU_VERIFIKASI_PEMBAYARAN` | "Bukti pembayaran diterima. Menunggu verifikasi admin." + expected window from FAQ | `pending` |
| Upload scan in progress (PUB-016) | "Dokumen sedang diperiksa. Anda dapat melanjutkan; unduhan tersedia setelah pemeriksaan selesai." | `pending` |
| File rejected by scan | "Dokumen tidak dapat diterima." + reason + re-upload | `danger` |
| `MENUNGGU_KETERSEDIAAN` | "Menunggu konfirmasi ketersediaan dari pengelola." | `pending` |
| Quote expiring | "Penawaran berlaku sampai {time}." | `pending` |
| Quote expired (`KEDALUWARSA`) | "Penawaran sudah kedaluwarsa." + request new quote | `neutral` |

**Upload component states:** `idle` → `uploading` (determinate progress bar, cancellable) → `scanning` (`pending`, indeterminate) → `accepted` (`success`) → `rejected` (`danger`, with reason + retry).

A file in quarantine is **never** previewable, downloadable, or thumbnailed (`AGENTS.md`; [`file-upload-pipeline.md`](../security/file-upload-pipeline.md)). Show a filename, type icon, and size — nothing more. When a signed URL is issued, surface its 5-minute validity in the UI so a user is not surprised by a dead link.

### 6.8 Success

**Quiet.** No confetti, no animated checkmark, no exclamation marks.

Step 9 (PUB-018) shows, per `booking-wizard-fields.md`: order reference (`font-mono`, copyable, one-tap copy) · current status badge · invoice/receipt availability · **email delivery status** · **WhatsApp delivery status or the reason it is unavailable** · admin/operator notification status where safe to display · next action · support contact · timeline link.

> **Never claim a delivery you cannot evidence.** `AGENTS.md`: *"Do not claim WhatsApp/email delivery without delivery state."* Render three distinct visuals: `success` "Terkirim", `pending` "Sedang dikirim", `neutral` "WhatsApp belum tersedia" (when `G-WA-01` is closed). Never a static "Email & WhatsApp terkirim".

Autosave success is inline and quiet (§3.9) — never a toast.

### 6.9 Gated fallback mode banner

`overview.md` §15 defines server-side modes; the UI **must read the server value** — a front-end flag is insufficient.

| Mode | Value | Banner |
|---|---|---|
| `PaymentMode` | `MANUAL_COORDINATION` | `info`: online payment not yet available; Step 8 uses manual coordination. **Step 8 is never removed.** |
| `WhatsAppMode` | `EMAIL_IN_APP_FALLBACK` | `info`: notifications via email + in-app; WhatsApp not yet available |
| `PreNeedMode` | `INTEREST_ONLY` | `info`: registers interest; **no payment created** |
| `GraveSearchMode` | `MANUAL_ASSISTANCE` | `info`: registry search unavailable; manual assistance path |
| `G-OPS-01` closed | — | `urgent` intent: operating hours and coverage, **no acceptance claim**, hotline shown |

Placement: directly below the header, above `<main>`, full-bleed background with contained text. Dismissible **only** for informational modes — never for one that changes how a user must pay.

### 6.10 Support escape hatch

Required on **every** transactional screen (`AGENTS.md`, `screen-inventory.md` §D). Implementation: persistent `Bantuan` in the header (IA §2) **plus** a contextual support link in the footer of every wizard step, checkout, payment, and order-status screen. It must state channels and operating hours ([`faq-catalog.md`](../product/faq-catalog.md) §Customer Service) and carry the emergency disclaimer on PUB-060. Never a chat-bubble-only affordance — it must work with JS disabled.

---

## 7. Accessibility

Target: **WCAG 2.1 Level AA**. `lang="id"` on `<html>`.

### 7.1 Contrast — verified

Verified by [`verify-contrast.py`](verify-contrast.py) against `resources/css/tokens.css`.

Real output, re-run 21 Aug 2026 (OQ-12 resolution — Earth/Leaf ramps regenerated from the real
official logo, replacing the earlier render-based estimate; §1.2) against the shipped
`tokens.css` (ADR-0034):

```
$ python3 docs/design/verify-contrast.py
WCAG contrast verification — resources/css/tokens.css
79 colour tokens parsed, 49 pairs asserted

PASS   16.67  (min 4.5)  text-strong on surface-raised  #1A1F1F on #FFFFFF
PASS   12.86  (min 4.5)  headings on surface-raised  #2D3333 on #FFFFFF
PASS    8.92  (min 4.5)  text-default (body) on surface-raised  #444B4B on #FFFFFF
PASS    6.47  (min 4.5)  text-muted on surface-raised  #576060 on #FFFFFF
PASS    4.53  (min 4.5)  text-placeholder on surface-raised  #6F7878 on #FFFFFF
PASS    8.39  (min 4.5)  text-default on surface-page  #444B4B on #F7F8F8
PASS    8.17  (min 4.5)  text-default on surface-warm  #444B4B on #F9F4F0
PASS   14.21  (min 4.5)  text-strong on secondary-100  #1A1F1F on #E1F1E4
PASS    8.34  (min 4.5)  text-default on secondary-50  #444B4B on #F2F9F3
PASS   15.58  (min 4.5)  text-strong on secondary-50  #1A1F1F on #F2F9F3
PASS    3.96  (min 3.0)  text-disabled on surface-disabled  #6F7878 on #EEF0F0
PASS   10.25  (min 4.5)  white on primary-600  #FFFFFF on #563B26
PASS   12.16  (min 4.5)  white on primary-700 (hover)  #FFFFFF on #47311F
PASS    5.36  (min 4.5)  white on success-600  #FFFFFF on #1C7A44
PASS    5.05  (min 4.5)  white on warning-600  #FFFFFF on #9A6300
PASS    7.34  (min 4.5)  white on danger-600  #FFFFFF on #A32435
PASS    9.37  (min 4.5)  white on danger-700 (hover)  #FFFFFF on #871D2C
PASS    7.66  (min 4.5)  white on info-600  #FFFFFF on #3A4E9B
PASS   11.13  (min 4.5)  primary-700 on primary-50  #47311F on #F9F4F0
PASS   11.60  (min 4.5)  primary-800 on primary-100  #382719 on #F0E6DE
PASS    6.82  (min 4.5)  success-700 on success-50  #166237 on #EEF8F1
PASS    7.94  (min 4.5)  success-800 on success-100  #134E2D on #D5EEDD
PASS    5.99  (min 4.5)  warning-700 on warning-50  #855400 on #FDF6EB
PASS    7.25  (min 4.5)  warning-800 on warning-100  #6A4400 on #FAEACB
PASS    8.49  (min 4.5)  danger-700 on danger-50  #871D2C on #FDF1F2
PASS    9.01  (min 4.5)  danger-800 on danger-100  #6E1825 on #FBDCDF
PASS    8.65  (min 4.5)  info-700 on info-50  #31417F on #F1F3FC
PASS    9.28  (min 4.5)  info-800 on info-100  #293568 on #E0E5F8
PASS    7.72  (min 4.5)  secondary-700 on secondary-50  #2A5833 on #F2F9F3
PASS    9.08  (min 4.5)  secondary-800 on secondary-100  #214629 on #E1F1E4
PASS   10.25  (min 4.5)  text-link on surface-raised  #563B26 on #FFFFFF
PASS   12.16  (min 4.5)  text-link-hover on surface-raised  #47311F on #FFFFFF
PASS   11.43  (min 4.5)  text-link on surface-page  #47311F on #F7F8F8
PASS    7.34  (min 4.5)  error text on surface-raised  #A32435 on #FFFFFF
PASS    3.67  (min 3.0)  border-interactive on surface-raised  #7F8787 on #FFFFFF
PASS    3.45  (min 3.0)  border-interactive on surface-page  #7F8787 on #F7F8F8
PASS    3.36  (min 3.0)  border-interactive on surface-warm  #7F8787 on #F9F4F0
PASS   10.25  (min 3.0)  focus ring on surface-raised  #563B26 on #FFFFFF
PASS    9.63  (min 3.0)  focus ring on surface-page  #563B26 on #F7F8F8
PASS    9.38  (min 3.0)  focus ring on surface-warm  #563B26 on #F9F4F0
PASS    4.42  (min 3.0)  focus ring inverse on primary-600  #C9A386 on #563B26
PASS    7.34  (min 3.0)  border-error on surface-raised  #A32435 on #FFFFFF
PASS    4.70  (min 3.0)  urgent border on urgent bg  #9A6300 on #FDF6EB
PASS   10.25  (min 3.0)  primary heading on surface-raised  #563B26 on #FFFFFF
PASS    4.73  (min 3.0)  white on primary-500 (large only)  #FFFFFF on #986943
PASS   16.36  (min 4.5)  white on surface-inverse  #FFFFFF on #2A1D13
PASS   13.31  (min 4.5)  primary-100 on surface-inverse  #F0E6DE on #2A1D13
PASS   10.37  (min 4.5)  primary-200 on surface-inverse  #E0CAB8 on #2A1D13
PASS   16.67  (min 4.5)  white on neutral-900  #FFFFFF on #1A1F1F

Hue separation of semantic families (600 shade):
  primary      26.2 deg
  success     145.5 deg
  info        227.6 deg
  danger      352.0 deg
  secondary   131.8 deg
  warning      38.6 deg

RESULT: PASS — all 49 pairs meet WCAG 2.1 AA
```

Selected measured ratios (full output above):

| Pair | Ratio | Min |
|---|---|---|
| body text `neutral-700` on white | **8.92** | 4.5 |
| muted `neutral-600` on white | **6.47** | 4.5 |
| placeholder `neutral-500` on white | **4.53** | 4.5 |
| white on `primary-600` (Earth, primary button) | **10.25** | 4.5 |
| white on `success-600` | **5.36** | 4.5 |
| white on `warning-600` | **5.05** | 4.5 |
| white on `danger-600` (tuned, ADR-0034) | **7.34** | 4.5 |
| white on `info-600` | **7.66** | 4.5 |
| badge `*-800` on `*-100` (all six families) | **7.25 – 11.60** | 4.5 |
| link `primary-600` on white | **10.25** | 4.5 |
| `border-interactive` on white / page / warm | **3.67 / 3.45 / 3.36** | 3.0 |
| focus ring `primary-600` on white | **10.25** | 3.0 |
| inverse focus ring `primary-300` on `primary-600` | **4.42** | 3.0 |
| white on `surface-inverse` (footer) | **16.36** | 4.5 |
| disabled text `neutral-500` on `neutral-100` | **3.96** | 3.0 |

**Four findings from that verification worth keeping visible:**

1. `warning-600` was originally `#A66B00` and measured **4.44:1** — a real AA failure for a white label. Darkened to `#9A6300` → 5.05:1.
2. `neutral-300` (1.71:1) and `neutral-400` (2.67:1) **both fail WCAG 1.4.11** as input borders. This is why `--color-neutral-450` (`#7F8787`, 3.67:1) exists and why `--mk-border-interactive` is mandatory for control boundaries. `neutral-200`/`300` remain valid for **decorative** dividers only, where 1.4.11 does not apply.
3. **Danger's hue was tuned −11° when Earth replaced Petrol as `primary` (ADR-0034 D4).** Earth's hue (≈26°) sits only ≈24° from the pre-existing danger hue (≈3°) — below the verifier's `HUE_MIN_SEPARATION = 30.0°` for the `primary`/`success`/`info`/`danger` status families. Per §9.4 ("fix the token, never the assertion"), every danger shade's hue was rotated −11° (lightness/saturation held), moving 600 from `#A32A24` (hue 3°) to `#A32435` (measured hue **352.0°**). Measured primary/danger separation after the tune: **≈34.2°** (`|26.2 − 352.0| = 325.8`, `360 − 325.8 = 34.2`), clearing the 30° floor. The OQ-12 hue refresh moved primary from 26.1° to 26.2° — negligible drift, the 30° floor still clears by the same margin.
4. **`primary-500` carries only a thin AA margin as a white-text fill — do not use it for that.** Measured **4.73:1**, barely above the 4.5:1 body-text floor (versus `primary-600`'s comfortable 10.25:1). The verifier only asserts the large-text 3.0 minimum for this pair (see `verify-contrast.py`'s own pairing), which is why it shows `(large only)` above — but the number is close enough to the normal-text floor that a slightly different rendering environment or sub-pixel AA could tip it under 4.5:1. Treat `500` as a mid-ramp surface/decorative swatch, not a button or badge fill; fills stay on `600` (§3.1).

**Re-run this script in CI on every colour change** (§9.5).

### 7.2 Focus

Single global treatment — `--mk-focus-ring`: 2 px `primary-600` ring at 2 px offset. `focus-visible` only (no ring on mouse click), but **`:focus-visible` must never be replaced by removing focus entirely.** `outline: none` without a replacement ring is a lint failure.

On brand-filled surfaces use `--mk-focus-color-inverse` (`primary-300`, 4.42:1 against `primary-600`).

Focus order follows DOM order. Modals trap focus and restore it to the trigger. The skip link is the first focusable element.

### 7.3 Touch targets

**44 × 44 px minimum** (`--mk-touch-min`, `min-h-11 min-w-11`) for every interactive element on public/mobile surfaces — WCAG 2.5.5 and `AGENTS.md` mobile-first.

- Visual size may be smaller (a 20 px checkbox, a 28 px stepper dot) as long as the **hit area** is 44 px.
- Minimum 8 px between adjacent targets.
- The only exception is `size=sm` (36 px) in Filament tables on pointer devices (§3.1).
- Bottom-of-screen actions respect `--mk-safe-bottom`.

### 7.4 Semantics and ARIA

| Component | Requirements |
|---|---|
| Button | `<button>` with real `type`. Icon-only → `aria-label`. `aria-busy` when loading. |
| Field | `<label for>` always. `aria-invalid`, `aria-describedby` for hint + error. |
| Stepper | `role="group"` + `aria-label`; `aria-current="step"`; `role="progressbar"` with min/now/max |
| Modal | `role="dialog"` `aria-modal="true"` `aria-labelledby`; focus trap; `Esc` |
| Alert | Post-submit → `role="alert"`. Ambient → `role="status"` `aria-live="polite"` |
| Autosave | `aria-live="polite"` region; never steals focus |
| Table | `<caption class="sr-only">`; `<th scope>`; `aria-sort` on sortable headers |
| Badge | Text label always present; icon `aria-hidden` |
| Nav | `<nav aria-label>`; `aria-current="page"` |
| Skeleton | `sr-only` loading announcement |
| Upload | Progress via `role="progressbar"`; state changes announced `polite` |

### 7.5 Beyond colour

Every status uses **colour + icon + Indonesian text**. Every error uses **icon + text**, not a red border alone. Charts and availability indicators need a non-colour differentiator (shape, label, pattern).

### 7.6 Reduced motion, zoom, forced colours

- `prefers-reduced-motion: reduce` → all durations 1 ms; the state change still occurs (`tokens.css` §3).
- All sizing in `rem`; layout must survive **200 % zoom** and 320 px width without horizontal scroll (WCAG 1.4.10).
- `forced-colors: active` handled in `tokens.css` §4 — focus and interactive borders map to system colours.
- Text spacing overrides (WCAG 1.4.12) must not clip content — avoid fixed heights on text containers.

### 7.7 Not covered here

Screen-reader testing, keyboard-only walkthroughs, and automated axe/Lighthouse runs are **required before release** ([`release-gates.md`](../testing/release-gates.md)) and have **not** been performed (§12).

---

## 8. Mapping to the stack

> **Version caveat — read before implementing.** [`technology-baseline.md`](../architecture/technology-baseline.md) pins Tailwind CSS **4.1+**, Livewire **4**, Filament **5**, Node **24**. None of these is installed in this repository — there is no `package.json`, no `composer.json`, and no `Dockerfile` (verified: all eight §5 lockfile artefacts are absent). **The snippets below are written against the documented baseline and have not been compiled.** Treat §8.3 (Filament 5 theming) as the least certain part of this document — see OQ-09 and §12.

### 8.1 File layout

```
resources/
├── css/
│   ├── tokens.css              ← SINGLE SOURCE OF TRUTH for design values
│   ├── app.css                 ← public site entry (imports tailwind + tokens)
│   └── print.css               ← invoice / kwitansi / agreement / certificate
│   (no filament/ theme directory — superseded 26 Aug 2026, see §8.3:
│    admin/vendor panels carry no custom theme CSS at all)
├── js/
│   └── app.js
└── views/
    └── components/mk/          ← Blade primitives from §3
docs/design/
├── design-system.md            ← this document
└── verify-contrast.py          ← CI gate for §7.1
```

### 8.2 Tailwind CSS 4.1 — CSS-first (canonical)

Tailwind 4 moved theme configuration **into CSS** via `@theme`. `tokens.css` is written for that model: `@theme` both generates utilities and emits every token as a `:root` custom property, so Filament, print CSS, and plain CSS can read `var(--color-primary-600)` with no duplication.

```css
/* resources/css/app.css */
@import "tailwindcss";

/* Tokens MUST come after the Tailwind import. */
@import "./tokens.css";

/* Tell Tailwind where to scan for utilities (v4 replaces `content` config). */
@source "../views/**/*.blade.php";
@source "../js/**/*.js";
@source "../../app/Livewire/**/*.php";
@source "../../app/Filament/**/*.php";

/* ---------------------------------------------------------------------------
 * Custom utilities for semantic tokens that have no Tailwind theme namespace
 * (z-index layers, motion durations, touch sizing). This is what makes
 * `z-modal` and `duration-fast` legal instead of `z-[1400]`.
 * ------------------------------------------------------------------------ */
@utility z-sticky-cta { z-index: var(--mk-z-sticky-cta); }
@utility z-dropdown   { z-index: var(--mk-z-dropdown); }
@utility z-bottomnav  { z-index: var(--mk-z-bottomnav); }
@utility z-header     { z-index: var(--mk-z-header); }
@utility z-backdrop   { z-index: var(--mk-z-backdrop); }
@utility z-modal      { z-index: var(--mk-z-modal); }
@utility z-popover    { z-index: var(--mk-z-popover); }
@utility z-toast      { z-index: var(--mk-z-toast); }
@utility z-tooltip    { z-index: var(--mk-z-tooltip); }
@utility z-skiplink   { z-index: var(--mk-z-skiplink); }

@utility duration-instant { transition-duration: var(--mk-duration-instant); }
@utility duration-fast    { transition-duration: var(--mk-duration-fast); }
@utility duration-base    { transition-duration: var(--mk-duration-base); }
@utility duration-slow    { transition-duration: var(--mk-duration-slow); }
@utility duration-slower  { transition-duration: var(--mk-duration-slower); }

/* Enforces the 44px floor without hand-written px anywhere. */
@utility touch-target { min-height: var(--mk-touch-min); min-width: var(--mk-touch-min); }

/* ---------------------------------------------------------------------------
 * Base layer: global defaults so components do not repeat themselves.
 * ------------------------------------------------------------------------ */
@layer base {
  html { -webkit-text-size-adjust: 100%; }
  body {
    background-color: var(--mk-surface-page);
    color: var(--mk-text-default);
    font-family: var(--font-sans);
    font-size: var(--text-base);
    line-height: var(--text-base--line-height);
    -webkit-font-smoothing: antialiased;
  }
  h1, h2, h3, h4 { color: var(--mk-text-strong); font-weight: var(--font-weight-semibold); }
  h1, h2 { letter-spacing: var(--tracking-tight); }
  a { color: var(--mk-text-link); }
  a:hover { color: var(--mk-text-link-hover); }

  /* Global focus. Never override this to `outline: none` without a ring. */
  :focus-visible {
    outline: var(--mk-focus-width) solid var(--mk-focus-color);
    outline-offset: var(--mk-focus-offset);
  }
}

/* Self-hosted fonts — no CDN (see §1.4). */
@font-face {
  font-family: "Inter var";
  font-style: normal;
  font-weight: 100 900;
  font-display: swap;
  src: url("/fonts/inter-var-latin.woff2") format("woff2");
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+2000-206F;
}
```

**Utilities generated from `tokens.css`** — use these, never arbitrary values:

| Token namespace | Generated utilities |
|---|---|
| `--color-*` | `bg-primary-600` `text-neutral-700` `border-neutral-450` `ring-primary-600` |
| `--spacing` | `p-4` `gap-6` `h-11` `min-h-11` `mt-10` (all 4 px multiples) |
| `--text-*` | `text-base` `text-2xl` (line-height applied automatically) |
| `--font-*` | `font-sans` `font-display` `font-mono` |
| `--font-weight-*` | `font-medium` `font-semibold` |
| `--radius-*` | `rounded-md` `rounded-lg` `rounded-xl` |
| `--shadow-*` | `shadow-sm` `shadow-md` `shadow-lg` |
| `--breakpoint-*` | `xs:` `sm:` `md:` `lg:` `xl:` `2xl:` |
| `--container-*` | `max-w-prose` `max-w-form` `max-w-content` |
| `--ease-*` | `ease-standard` `ease-decelerate` `ease-emphasized` |

#### Legacy `tailwind.config.js` (compatibility only — not the source of truth)

Tailwind 4 still reads a JS config when explicitly loaded with `@config`. Use it **only** if a plugin requires it. Do **not** duplicate token values here — reference the CSS variables so `tokens.css` stays authoritative:

```js
// tailwind.config.js — COMPATIBILITY SHIM. Not the source of truth.
// Load explicitly from CSS:  @config "../../tailwind.config.js";
// Prefer @theme in resources/css/tokens.css. Adding a literal value here is a
// governance violation (§9.2) — it creates a second source of truth.
export default {
  content: [
    './resources/views/**/*.blade.php',
    './app/Livewire/**/*.php',
    './app/Filament/**/*.php',
    './vendor/filament/**/*.blade.php',
  ],
  theme: {
    extend: {
      // Every value REFERENCES the token; none defines one.
      colors: {
        primary: {
          50:'var(--color-primary-50)',  100:'var(--color-primary-100)',
          200:'var(--color-primary-200)', 300:'var(--color-primary-300)',
          400:'var(--color-primary-400)', 500:'var(--color-primary-500)',
          600:'var(--color-primary-600)', 700:'var(--color-primary-700)',
          800:'var(--color-primary-800)', 900:'var(--color-primary-900)',
          950:'var(--color-primary-950)',
        },
        // …repeat for secondary / neutral / success / warning / danger / info
      },
      borderRadius: { md:'var(--radius-md)', lg:'var(--radius-lg)', xl:'var(--radius-xl)' },
      zIndex: { header:'var(--mk-z-header)', modal:'var(--mk-z-modal)', toast:'var(--mk-z-toast)' },
      minHeight: { touch:'var(--mk-touch-min)' },
      screens: { xs:'22.5rem' },
    },
  },
  plugins: [],
}
```

> **Caveat:** wrapping colours in `var()` disables Tailwind's automatic opacity modifiers in some v3-era code paths (`bg-primary-600/50`). Under Tailwind 4's `@theme`, opacity modifiers work via `color-mix()` and are unaffected. This is one more reason `@theme` is canonical and this shim is a last resort. **Not verified against a build** (§12).

### 8.3 Filament 5 panels — ⚠️ SUPERSEDED, 26 Aug 2026 (explicit project-owner decision)

**Current state: admin/vendor Filament panels do NOT follow this brand system.** They use Filament's own default, out-of-the-box appearance — stock primary/gray colour scheme, stock font stack, no custom theme CSS, no logo/wordmark. `AdminPanelProvider`/`VendorPanelProvider` carry only `->brandName('Makam Admin')`/`->brandName('Makam Vendor')` (plain text, for functional identification — which app is this — not a designed brand element). This is a deliberate reversal of everything this section originally described, not a bug or a partial implementation: the project owner decided admin/vendor panels are internal back-office tools, separate from the public-facing Earth-brown/Leaf-green identity, which stays exactly as documented in §1–§7 for the public site only. See `AdminPanelProvider`'s own doc block ("SEVENTH change") for the full record.

Everything below this point in §8.3 is a **historical record of the superseded approach** (Batch 2.4/S2-T3, then ADR-0034 Task 5, then the SEC-08/font-provider back-and-forth), kept for provenance — it does **not** describe current shipped code. Do not use it as a guide for new work; do not re-add `->colors()`, `->font()`, `->viteTheme()`, or `->brandLogo()` to either panel provider without a new explicit owner decision reversing the 26 Aug 2026 reversal.

<details>
<summary>Historical: token-derived Filament theming (shipped 25 Jul–26 Aug 2026, then reverted)</summary>

`AdminPanelProvider` previously loaded a `tokens.css`-generated palette (resolving OQ-09) via `->colors($this->filamentColors())`, reading `app/Support/Design/generated/FilamentPalette.php` — produced by `php artisan design:generate-filament-palette` and diff-checked by `php artisan design:verify-filament-palette` (§9.5 gate 6). A companion `resources/css/filament/admin/theme.css` imported `tokens.css` and a self-hosted Inter font, wired in via `->viteTheme(...)`. Both panels also carried `->brandLogo(asset('brand/mark-96.png'))->brandLogoHeight('2rem')` (ADR-0034 Task 5).

None of that is wired into either panel provider any more. The generator class (`app/Support/Design/FilamentPaletteGenerator.php`) and its two artisan commands (`GenerateFilamentPaletteCommand`, `VerifyFilamentPaletteCommand`) still exist in the repo and the CI `design:verify-filament-palette` step (§9.5 gate 6) still runs, but **the generated file they produce has no consumer** — no panel reads it. This is intentionally left as-is by the 26 Aug 2026 reversal rather than deleted; a follow-up may remove the now-dead generator/CI-gate machinery, but that is a separate, not-yet-made decision (flagged, not actioned, in the PR that made this reversal).

`resources/css/filament/admin/theme.css` itself was deleted (it carried no non-branding structural fix, so there was nothing to keep), along with its `vite.config.js` Vite entry.

**Filament status badges still use the §3.7 mapping** where a Resource exists — `StatusIntent` is a public-site/domain concern, not a panel-branding one, and is unaffected by this reversal:

```php
Tables\Columns\TextColumn::make('status')
    ->badge()
    // Intent resolution lives in ONE place, shared with the public site.
    ->color(fn (string $state): string => StatusIntent::filamentColor($state))
    ->icon(fn (string $state): string => StatusIntent::icon($state))
    ->formatStateUsing(fn (string $state): string => StatusIntent::label($state));
```

Whether this remains correct once panels stop carrying the tokens.css palette (i.e. whether `StatusIntent::filamentColor()` should now return one of Filament's default colour keys instead of a token-derived hex) is **not resolved by this reversal** and needs a follow-up check once a Resource actually renders a status badge in CI.

</details>

**OQ-09 — reopened by the 26 Aug 2026 reversal.** It was previously marked resolved (generator + CI diff, described above); it is not moot again in the sense the original question asked ("Filament resolves colours in PHP and cannot read CSS variables — how do we avoid drift?"), because there is no longer anything to keep from drifting: no panel reads a colour array at all.

### 8.4 Livewire 4

- **Loading:** `wire:loading.delay` + `wire:target` (§6.1). Never a bare `wire:loading` on a page-level container.
- **Optimistic UI is forbidden on money paths.** `AGENTS.md`: never mark paid from a browser return URL. Payment state renders only from server state.
- **Autosave:** `wire:model.live.debounce.500ms` for text fields feeding the 10 s autosave; the indicator is driven by a server-confirmed `savedAt`, never by a local timer.
- **Server-side modes:** gate/mode values (§6.9) are passed from the server into the component. Do not read a JS flag.
- `wire:key` on every loop item so Livewire's DOM diffing does not reorder cards mid-interaction.
- Alpine only for isolated UI mechanics (dropdown open/close, focus trap). No global Alpine store for domain state (`overview.md` §3).

```blade
{{-- Wizard step: sticky CTA that never overlaps navigation --}}
<div class="sticky bottom-0 z-sticky-cta border-t border-neutral-200 bg-white
            px-4 py-4 pb-[calc(1rem+var(--mk-safe-bottom))] shadow-md
            md:static md:border-0 md:bg-transparent md:p-0 md:shadow-none">
  <div class="mx-auto flex max-w-form flex-col-reverse gap-3 md:flex-row md:justify-between">
    <x-mk.button variant="tertiary" wire:click="stepSebelumnya">Kembali</x-mk.button>
    <x-mk.button variant="primary" size="lg" :full="true"
                 wire:click="stepBerikutnya" wire:loading.attr="disabled"
                 wire:target="stepBerikutnya">Lanjutkan</x-mk.button>
  </div>
</div>
```

### 8.5 Print / document stylesheet

**Not yet built** — `print.css` does not exist in this repository (ADR-0034 D10.1); the snippet below is the intended shape, not a shipped file. **Its body font is `var(--font-document)`, not `var(--font-display)`.** `--font-display` is now the Poppins brand face (§1.4) and must never appear on a certificate/agreement/invoice; `--font-document` (Source Serif 4) exists specifically so a printed legal document keeps serif gravitas regardless of what the public site's display face becomes.

```css
/* resources/css/print.css — invoice, kwitansi, agreement, certificate */
@import "tailwindcss";
@import "./tokens.css";   /* tokens.css §5 already overrides for @media print */

@page { size: A4; margin: 18mm 16mm; }

@layer base {
  /* --font-document, NOT --font-display — that is Poppins now (§1.4, ADR-0034 D7). */
  body { font-family: var(--font-document); font-size: 11pt; color: #000; }
  .page-break { break-after: page; }
  a[href]::after { content: " (" attr(href) ")"; font-size: 9pt; }
  /* Never print a signed document URL — it expires in 5 minutes and is audited. */
  a[data-signed-url]::after { content: ""; }
}
```

### 8.6 Email brand usage

No email templates exist yet in this repository — this is a rule section for future template work, not a description of anything shipped. When email templates are built, brand usage in email is limited to:

- **Reference the hosted `/brand/` lockup URL, never an inline embed.** Link or `<img src>` the mark/lockup from the deployed `public/brand/` assets (§8, `<x-mk.logo>`, ADR-0034 Task 3/4) — do not attach or inline the logo as a MIME attachment.
- **Brown-on-white only.** Email always uses the normal (brown-on-white) mark; never the inverse (white-on-brown) variant — email clients handle transparency and dark/inverse backgrounds unreliably, so there is no dark-surface email context to justify it.
- **No attachments.** Consistent with `AGENTS.md`'s notification rules (email/WhatsApp never carry private attachments) — the brand mark is referenced by URL, not attached, and this applies regardless of the attachment's sensitivity.

---

## 9. Governance

### 9.1 Source-of-truth precedence

| Rank | Artefact | Owns |
|---|---|---|
| 1 | [`resources/css/tokens.css`](../../resources/css/tokens.css) | **Every design value.** Colour, spacing, type, radius, shadow, z-index, motion, sizing. |
| 2 | `docs/design/design-system.md` (this file) | Component contracts, state patterns, layout rules, rationale |
| 3 | `resources/views/components/mk/*` | Canonical implementation of §3 |
| 4 | `docs/design/verify-contrast.py` | Executable accessibility gate for §7.1 |
| 5 | Filament panel providers | **Superseded 26 Aug 2026 (§8.3):** admin/vendor panels no longer derive any value from rank 1 — they use Filament's stock appearance and carry no design-token wiring at all. This row is kept only as a historical description of ranks 1–4's own scope (public site, not panels). |

A conflict between ranks is a **defect**. Rank 1 wins for values; rank 2 wins for behaviour. Do not resolve a conflict by editing the lower rank to match.

### 9.2 Rules for developers and AI agents (**enforceable**)

**MUST**

1. Reference a token for every design value.
2. Use the `<x-mk.*>` primitives in §3. Extend them rather than forking.
3. Design and verify at **320 px first**, then `md`, then `lg`.
4. Implement **all ten** required states (§6) for every transactional screen.
5. Resolve status → intent through the single `StatusIntent` helper (§3.7). Never `match` on an enum inside a Blade view.
6. Read gate/fallback modes from the **server** (§6.9).
7. Keep every interactive target at **44 px** on public surfaces.
8. Re-run `verify-contrast.py` after any colour change.
9. ~~Keep Filament's PHP colour array in sync with `tokens.css` (§8.3), regenerating rather than editing.~~ **Superseded 26 Aug 2026:** admin/vendor Filament panels no longer consume this array at all (§8.3) — this rule applied only while they did.

**MUST NOT**

1. ❌ Hardcode a hex, `rgb()`, `hsl()`, px size, duration, or shadow outside `tokens.css`.
2. ❌ Use Tailwind arbitrary values for design decisions — `text-[#12545E]`, `p-[13px]`, `z-[9999]`, `duration-[250ms]`.
   *(Permitted exception: `var()` references to semantic tokens with no utility, e.g. `bg-[var(--mk-surface-overlay)]`.)*
3. ❌ Write a raw `z-index`. Use the named layer utilities.
4. ❌ `outline: none` without a replacement focus ring.
5. ❌ `opacity-50` for disabled states — it silently breaks contrast.
6. ❌ Convey status by colour alone.
7. ❌ Use `secondary` (**Leaf**) as a fill, badge, button, or alert. The rule stands from v0.1 — only the family changed (Sandstone → Leaf, ADR-0034). *(A `secondary-100`-tile-with-`secondary-800`-icon decorative medallion, §1.2(b), is a surface-tint usage already inside the cage — not an exception to this rule.)*
8. ❌ Add `dark:` utilities before OQ-07 is resolved.
9. ❌ Rename, reorder, or hide a product label, route, menu item, or booking step (§0.1). **Exception, 2 Sep 2026:** the wizard step-count reduction (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`) is a deliberate, project-owner-authorized departure — see §3.9's own updated step count and the `AGENTS.md` note this plan's Task 10 Step 1 adds. This item's general rule is otherwise unchanged.
10. ❌ Style a pending state as success, or claim a notification delivery without delivery state.
11. ❌ Preview, thumbnail, or link a quarantined document.
12. ❌ Introduce a new token without §9.4.
13. ❌ **Reuse an accent/semantic colour token for a second, different meaning.** One accent, one purpose (§1.2(d), added 26 Aug 2026) — e.g. `--mk-text-price` renders a confirmed monetary figure and nothing else; it must never be repurposed as a generic "emphasis" or "strong" colour for unrelated content, the same way `secondary`/Leaf (rule 7) must never be repurposed as a fill.

### 9.3 Definition of Done for any UI change

- [ ] All ten required states implemented and manually exercised (§6)
- [ ] Verified at 320 px, 360 px, 768 px, 1024 px, 1280 px
- [ ] Keyboard-only path works; focus visible and ordered; modal focus returns
- [ ] Zero hardcoded design values (grep gate, §9.5)
- [ ] `verify-contrast.py` exits 0
- [ ] Status badges use the §3.7 mapping
- [ ] Gate/mode read from server, not a client flag
- [ ] Touch targets ≥ 44 px
- [ ] `prefers-reduced-motion` respected
- [ ] Weight budget (§4.6) not exceeded
- [ ] Screen added/updated in [`screen-inventory.md`](../product/screen-inventory.md) with its states
- [ ] Browser test covers the screen ([`test-strategy.md`](../testing/test-strategy.md)) — and traceability is only marked `Covered` when that test **exists and passes**

### 9.4 Changing a token

1. Open an ADR under `docs/adr/` (next free number; ADR-0028 is the natural home for "Adopt token-driven design system").
2. State the problem, the affected tokens, and the blast radius.
3. Edit **`tokens.css` only**.
4. Run `verify-contrast.py`; if a pair regresses, fix the token, not the assertion.
5. Update this document's rationale sections and, if a component contract changes, `screen-inventory.md`.
6. Human review is mandatory for anything touching security, authorization, financial, or privacy surfaces (`AGENTS.md`).

Removing or weakening an assertion in `verify-contrast.py` to make a build pass is an accessibility regression and must be rejected in review.

### 9.5 CI enforcement — implemented 25 Jul 2026 (Batch 2.5, S2-T4)

All seven gates below are live and blocking merges. Gates 1–3 and 4–5 run in `ci/verify-docs.sh` (GATE 1–3 and GATE 11–12 respectively — see that script's own numbering note for why 4/5 aren't literally "GATE 4"/"GATE 5" there, it already had unrelated gates at those numbers). Gates 6 and 13 run as steps in `.github/workflows/ci.yml`'s `php` job, since both need a bootstrapped Laravel app that `verify-docs.sh` (pure bash+python, no `vendor/`) cannot provide.

```bash
# 1. Accessibility gate — hard fail
python3 docs/design/verify-contrast.py

# 2. No hardcoded colours outside tokens.css
! grep -rInE '#[0-9A-Fa-f]{3,8}\b' \
    --include='*.blade.php' --include='*.css' --include='*.js' --include='*.php' \
    resources/ app/ | grep -v 'resources/css/tokens.css'

# 3. No Tailwind arbitrary design values (var() references are allowed)
! grep -rInE '\b(text|bg|border|p|m|w|h|gap|z|rounded|shadow|duration)-\[[^]]*\]' \
    --include='*.blade.php' resources/ app/ | grep -v 'var(--'

# 4. No raw z-index
! grep -rInE 'z-index\s*:\s*[0-9]' --include='*.css' --include='*.blade.php' resources/ \
  | grep -v 'resources/css/tokens.css'

# 5. No focus suppression without replacement
! grep -rIn 'outline:\s*none' --include='*.css' resources/ | grep -v 'focus-visible'

# 6. Filament PHP palette matches tokens.css (see §8.3 known gap)
php artisan design:verify-filament-palette

# 13. Every Blade view survives real compilation with no lost content —
#     catches the N-14 doc-comment-corruption class (a {{-- --}} comment that
#     merely mentions the literal "@php" token pairs with a later real
#     @endphp and silently swallows everything between, including its own
#     closing --}} — which then makes compileComments() race forward to the
#     NEXT real --}} in the file, erasing the swallowed content entirely).
#     php -l cannot see this: the compiled output is still syntactically
#     valid PHP, just missing template content. Proven 8 Aug 2026 against
#     this repo's own real, previously-live incident in faq/index.blade.php.
php artisan blade:verify-content-survival
```

Also recommended: `axe-core` in the browser-test suite, and a Lighthouse budget matching §4.6. Both are **required by [`release-gates.md`](../testing/release-gates.md)** before production activation and are currently unimplemented.

---

## 10. Quick reference

```
COLOUR    primary-600 #563B26  brand/CTA/link/focus (Earth — logo-sampled, OQ-12 resolved)
          success-600 #1C7A44  DIBAYAR, SELESAI
          warning-600 #9A6300  MENUNGGU_*, Urgent, scan pending
          danger-600  #A32435  DITOLAK, error, failed (hue tuned −11°, ADR-0034)
          info-600    #3A4E9B  gated-fallback banners
          neutral-700 #444B4B  body text
          neutral-450 #7F8787  interactive borders  ← not 300
          secondary   #336B3E  Leaf — surface/accent ONLY, never a fill (logo-sampled, OQ-12 resolved)
          text-price  #382719  (primary-800) monetary figures ONLY — confirmed price/fee/total, never indicative

Each accent/semantic token above has exactly ONE purpose app-wide (§1.2(d), §9.2 MUST NOT 13).

TEXT      16px floor on all inputs · text-base body · 500 UI · 600 headings
SPACE     4px base · gutter p-4/md:p-6/lg:px-8 · section 40/64 · touch h-11
RADIUS    md 8 button+input · lg 12 card · xl 16 modal · no pills
Z         sticky-cta 900 < bottomnav 1100 < header 1200 < modal 1400 < toast 1600
MOTION    fast 120 · base 180 · slow 260 · max 400 · no bounce · no slide
CONTAINER prose 640 · form 768 (wizard) · content 1280
BREAK     320 base · xs 360 · sm 640 · md 768 · lg 1024 (nav switches) · xl 1280

STATES    loading · empty · validation · authorization · provider-unavailable
          duplicate-safe · pending · success · support · responsive   (all 10)

NEVER     hardcode a value · colour-only status · pending-as-success
          claim undelivered notification · preview a quarantined file
          countdown/urgency pressure · celebrate · pill button · dark mode
          reuse an accent token for a second purpose (§1.2(d))
```

---

## 11. OPEN QUESTIONS

These require a decision from design, product, or brand. **Each is a real fork, not a placeholder.** Until resolved, the stated default applies.

| ID | Question | Default in force | Blocks |
|---|---|---|---|
| **OQ-01** | Is **Petrol teal** the accepted brand primary? A green primary is culturally resonant for an Indonesian cemetery brand but collides with `success` (§1.2a). If green is mandated, `success` must move to teal and every §7.1 pair must be re-verified. | **Resolved (Earth, ADR-0034), 17 Aug 2026.** The official brand identity mandates Earth brown (`#563B26`, logo-sampled per OQ-12) as primary; Petrol is retired. The green-vs-success ambiguity this question worried about is now solved structurally, not by hue-avoidance: brand fills are brown, `secondary` (Leaf) never fills anything (§1.2b). | Whole palette — resolved |
| **OQ-02** | Is there an existing Makam.co.id brand identity — logo, colour, typeface? The live site at `makam.co.id` is a static landing page (14 KB `index.html`) not derived from this repo; it was **not** treated as brand authority here. | **Resolved, 17 Aug 2026 (ADR-0034).** The stakeholder supplied an official identity render plus the *Filosofi Logo Makam.co.id* philosophy text in chat on 17 Aug 2026; it is now authoritative. See §1.2, §1.4. | §1.2, §1.4 — resolved |
| **OQ-03** | Is **Inter + Source Serif 4** acceptable, and is there budget/licence for a commercial alternative? Both chosen are open-licence and self-hostable. | **Amended, 17 Aug 2026 (ADR-0034).** Poppins added as `--font-display` (`h1`/`h2`, hero, header wordmark; philosophy: "modern, clean, digital, friendly, professional"), self-hosted via `@fontsource/poppins`, latin subset, weight 600 only. Inter is retained for body/UI/`h3`/`h4`/Filament. Source Serif 4 is retained too, moved to the new `--font-document` token (documents only, §1.4) so it can never be silently replaced by Poppins. | §1.4, §4.6 |
| **OQ-12** | Official brand hex values, a vector source for the mark, and a horizontal lockup are still outstanding. Every Earth/Leaf hex adopted in this pass was derived from a chat-reviewed render, not sampled from confirmed brand collateral (ADR-0034 D9), and every raster asset built from it (§8, Task 3 — `mark-96`, `mark-inverse-96`, `lockup-320`/`640`, `favicon.ico`, `apple-touch-icon.png`) is provisional for the same reason. | **Resolved 21 Aug 2026.** The real official logo (`docs/design/brand/source/logo.png`) is now in hand. `primary` Earth `#563B26` and `secondary` Leaf `#336B3E` are sampled directly from it (`docs/design/brand/sample-logo-colours.php`), and the full 50–950 ramps are regenerated from those anchors (`docs/design/brand/generate-ramp.php`) — see the OQ-12 note in §1.2. `primary-500` was found to carry only a thin AA margin as a white-text fill (4.73:1, §7.1 finding 4) and is documented as fill-ineligible in §3.1. The vector logo source and horizontal lockup asset rebuild are tracked separately (§8, Task 3) and are **not** part of this resolution. | §1.2, §3.1, §7.1 — resolved (hex values); §1.4, §8 raster assets — still outstanding |
| **OQ-04** | **Mobile bottom navigation** — approve or reject? IA §2 specifies hamburger + persistent Bantuan; a bottom nav would be a navigation change requiring product approval (§3.11). | IA-compliant header only; bottom nav **not shipped** | §3.11 |
| **OQ-05** | Which **icon set**? An outline set at 1.5 px stroke is assumed (Heroicons/Lucide class). Affects the SVG sprite and §4.6 budget. | Outline, 1.5 px, inline sprite | §3, §4.6 |
| **OQ-06** | Exact **Indonesian microcopy** for each state in §6. Strings here are illustrative; final copy needs a product/legal pass, especially payment, Urgent availability, and privacy notices (`faq-catalog.md` forbids publishing unsupported SLA or method). | Illustrative only | §6 |
| **OQ-07** | **Dark mode** — in or out? Absent from `screen-inventory.md`, so it currently has no required states and no test coverage. Adding it roughly doubles the visual QA surface. | **Out of MVP.** Not implemented (`tokens.css` §6) | §1.2, §7.1 |
| **OQ-08** | Is the **44 px** floor acceptable, or should public CTAs target 48 px given the Jabodetabek Android device mix? | 44 px (WCAG 2.5.5) | §7.3 |
| **OQ-09** | How is the **Filament PHP colour array** generated from `tokens.css`? Filament resolves colours in PHP and cannot read CSS variables, so today the hex values are duplicated (§8.3). A build-time generator plus CI diff is proposed but unwritten. | **Moot, 26 Aug 2026.** Was resolved via a generator + CI diff (`FilamentPaletteGenerator`, §8.3); superseded by the same-date decision that admin/vendor panels carry no custom colour array at all — nothing left to keep in sync. | §8.3, §9.5 |
| **OQ-10** | Does a **Content-Security-Policy** exist or is one planned? No CSP is defined in [`security-baseline.md`](../security/security-baseline.md). Self-hosted fonts and an inline SVG sprite are CSP-friendly, but Livewire/Alpine may need `script-src` accommodation. This should be decided **before** the scaffold, not retrofitted. | None defined — flagged as a gap | §1.4, §4.6 |
| **OQ-11** | Should `docs/design/` be added as a canonical Kiro steering document? | **Resolved, done 25 Jul 2026.** `design-system.md` and `tokens.css` are registered in `.kiro/steering/design.md` (a conditional steering file, loaded when work touches `resources/`, `app/Filament/`, `app/Livewire/`, or `docs/design/` — see `.kiro/steering/project.md`'s index) | Adoption |

---

## 12. NOT TESTED / NOT VERIFIED

Per `AGENTS.md`: *"Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT TESTED` explicitly."*

### Verified — executed, with evidence

| Item | Result | Evidence |
|---|---|---|
| WCAG 2.1 AA contrast, 49 documented pairs | **PASS** | `python3 docs/design/verify-contrast.py` → exit 0, 79 tokens parsed, 49/49 pass (§7.1) |
| Semantic hue separation ≥ 30° for primary/success/info/danger | **PASS** | Same script: 26.2° / 145.5° / 227.6° / 352.0° |
| Two real AA failures found and fixed during v0.1 authoring | **PASS** | `warning-600` 4.44 → 5.05; `neutral-300`/`400` borders 1.71/2.67 → `neutral-450` 3.67 |
| `tokens.css` parses as CSS token declarations | **PASS** | Verifier extracted all 79 `--color-*-<shade>` tokens |
| Re-verified 17 Aug 2026 against the shipped Earth/Leaf palette (ADR-0034) — all 46 pairs re-pass, including the danger-hue tune | **PASS** | `python3 docs/design/verify-contrast.py`, re-run for this document sync: 79 tokens parsed, 46/46 pass, `RESULT: PASS — all 46 pairs meet WCAG 2.1 AA` (full output §7.1); danger/primary separation ≈34.1° after the −11° hue tune (ADR-0034 D4), clearing the 30° floor |
| Re-verified 21 Aug 2026 against the OQ-12-resolved, logo-sampled Earth/Leaf palette — all 49 pairs re-pass | **PASS** | `python3 docs/design/verify-contrast.py`, re-run for OQ-12 resolution: 79 tokens parsed, 49/49 pass, `RESULT: PASS — all 49 pairs meet WCAG 2.1 AA` (full output §7.1); primary/danger separation ≈34.2° holds; `primary-500` white-text fill measures 4.73:1, a thin AA margin (§7.1 finding 4, §3.1) |

### NOT TESTED — updated as items get verified; not a static snapshot

This table was written when `makam-app` had no application code at all. The scaffold has since landed (`technology-baseline.md` §5's eight artefacts all exist) and CI runs on every push — most rows below predate that and many are now stale. Rows are corrected as each is actually closed, not left asserting a state that stopped being true.

| Item | Status |
|---|---|
| Tailwind 4 compilation of `tokens.css` / `@theme` / `@utility` / `@source` | **Verified** — CI's `frontend` job (`.github/workflows/ci.yml`) runs `npm run build` on every push and asserts CSS is emitted |
| Every generated utility name in §8.2 (`max-w-form`, `max-w-prose`, `max-w-content`, `duration-fast`, `z-modal`, `z-header`, `touch-target`, `h-11`, `h-13`, `xs:`, `border-neutral-450`, `ease-standard`, `text-base`) | **Verified 25 Jul 2026 — S2-T1 closed.** CI greps the compiled CSS for each (`resources/views/design-system-smoke-test.blade.php` forces the ones with no real usage yet — `max-w-form`/`prose`/`content`, `xs:` — to actually generate, since Tailwind's JIT scanner only emits what's literally referenced somewhere) |
| The `tailwind.config.js` shim, including the `var()`-in-colours opacity caveat | **NOT TESTED** |
| **Filament 5 theming (§8.3)** — panel boot itself | **Verified 25 Jul 2026** — `AdminPanelProvider` boots successfully in CI against the real, pinned `filament/filament` v5.7.3 (all tests green) |
| **Filament 5 theming (§8.3)** — `LocalFontProvider`, `discoverResources()`/`discoverPages()`/`discoverWidgets()` | **Moot, 26 Aug 2026** — the custom-theming approach this row described was reverted the same day (§8.3); `discoverResources()` itself is retained (unrelated to branding) but no font-provider question applies any more since neither panel requests a custom font |
| **Filament 5 theming (§8.3)** — brand colours/font/logo removed | **Reverted, 26 Aug 2026, explicit owner decision.** admin/vendor panels now use Filament's stock default appearance; `tests/Feature/Filament/PanelBrandingTest.php` asserts a plain-text `->brandName()` only and no `->brandLogo()`. Run against the pinned CI image + real Postgres/Redis for this batch — see the batch's PR for the result. |
| All Blade/Livewire snippets (§3.1, §3.2, §6.1, §8.4) | **NOT TESTED** — never rendered or compiled |
| `StatusIntent` helper and `design:verify-filament-palette` command | **Written and tested 25 Jul 2026** — `app/Support/Design/StatusIntent.php`, 26 tests green in CI (real PHPUnit run, not the earlier `php -l`-only claim); `design:verify-filament-palette` exists and works when run manually, not yet wired into CI (that's S2-T4/Batch 2.5) |
| Logo/wordmark — real Earth/Leaf mark in header + footer, placeholder "M" monogram retired (ADR-0034, Task 4) | **Test written, NOT RUN locally.** `<x-mk.logo>` rewritten; `tests/Feature/View/Components/MkLogoTest.php` (4 assertions) and `tests/Feature/View/BrandIdentityTest.php` (`GET /` renders header mark, footer inverse mark, favicon links) exist and are `php -l` clean, but PHPUnit needs PHP ≥8.5 and this host runs 8.3.6 — **CI evidence pending.** |
| Favicon set (`favicon.ico`, `apple-touch-icon.png`) and brand raster assets (`mark-96`, `mark-inverse-96`, `lockup-320`/`640`, PNG+WebP, Task 3) | **Generated for real, structurally verified on-host; not yet asserted by a run test.** Built by `BrandAssetBuilder` (ext-gd) against the real source PNG; file existence, real byte sizes, ICO magic/3-entry structure, and deterministic output across repeated builds were confirmed by direct on-host inspection (task-3-report.md). Wired into `<head>` (Task 4) and asserted by `BrandIdentityTest`, which is **NOT RUN locally** — same PHP-version limitation as above, CI evidence pending. |
| Rendered visual appearance of the palette | **NOT TESTED** — no screenshot, no browser, no device |
| Screen-reader behaviour (NVDA/VoiceOver/TalkBack) | **NOT TESTED** |
| Keyboard-only walkthroughs, focus-order verification, focus-trap behaviour | **NOT TESTED** |
| Automated a11y (axe-core, Lighthouse) | **NOT RUN** — no test harness exists |
| 200 % zoom and 320 px reflow (WCAG 1.4.10) | **NOT TESTED** |
| `forced-colors` / Windows High Contrast rendering | **NOT TESTED** |
| Front-end weight budget (§4.6) | **NOT MEASURED** — these are targets derived from `performance-and-capacity.md` §3, not observations |
| Print/PDF output for invoice, kwitansi, agreement, certificate | **NOT TESTED** |
| Real-device testing on the Jabodetabek Android mix | **NOT DONE** |
| Usability validation of the bereavement tone with actual users | **NOT DONE** — the tone rules in §2 are reasoned, not researched |
| CI gates in §9.5 | **NOT IMPLEMENTED** — no CI pipeline exists in this repository |

### Explicitly out of scope for v0.1

Illustration style, photography direction and sourcing, logo design, motion-graphic assets, e-mail template design (referenced by [`notification-matrix.md`](../contracts/notification-matrix.md) but not designed here), WhatsApp message templates, and dark mode (OQ-07).

---

## 13. Adoption checklist

Ordered, because some steps depend on earlier ones.

1. [x] Resolve **OQ-01** (brand primary) and **OQ-02** (existing identity) — **resolved 17 Aug 2026, ADR-0034** (Earth brown primary, Leaf green secondary); **OQ-12** (official hex values) — **resolved 21 Aug 2026**, logo-sampled Earth/Leaf hex now in `tokens.css` — everything downstream depends on the palette
2. [ ] Resolve **OQ-04** (bottom nav) — it is a navigation contract, not a style choice
3. [ ] Record **ADR-0028 — Adopt token-driven design system** (§9.4)
4. [x] Add `docs/design/design-system.md` to Kiro steering (**OQ-11**) so agents actually read it — done 25 Jul 2026, `.kiro/steering/design.md`
5. [ ] Scaffold Laravel 13 + the eight `technology-baseline.md` §5 artefacts (blocks all build verification)
6. [ ] Self-host the fonts; decide **OQ-10** (CSP) *before* the scaffold hardens
7. [x] Wire `app.css` per §8.2; confirm every utility in §8.2 actually generates — resolve any mismatch by fixing this document (**done 25 Jul 2026**, CI-enforced — see §12's NOT TESTED table)
8. [ ] Verify §8.3 against a real Filament 5 install; correct this document; then build the palette generator (**OQ-09**)
9. [ ] Build the `<x-mk.*>` primitives (§3) with the ten states (§6)
10. [ ] Implement `StatusIntent` as the single status → intent resolver (§3.7)
11. [ ] Add the CI gates in §9.5 — including `verify-contrast.py` as a hard fail
12. [ ] Add axe-core + Lighthouse budgets to the browser suite
13. [ ] Update `screen-inventory.md` with the designed states per screen
14. [ ] Only then mark traceability items `Covered` — and only where a test exists and passes
