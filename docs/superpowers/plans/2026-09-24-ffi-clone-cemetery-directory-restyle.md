# FFI Clone — Cemetery Directory Restyle (Listing + Detail) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the public cemetery directory listing page and single-cemetery detail page to match FFI's real filterable-grid → detail structural and visual pattern (card grid rhythm, filter-chip shape/colour, detail-page hero/info-block/CTA emphasis), reusing this project's own already-FFI-restyled `mk.*` primitives and `tokens.css` values. Pure visual/layout restyle — no filter behaviour, cemetery data, copy, or pricing changes.

**Architecture:** Both pages already render through the existing `App\Livewire\Public\Directory\CemeteryDirectoryIndex` / `CemeteryDetail` Livewire components and their Blade views (`resources/views/livewire/public/directory/index.blade.php`, `detail.blade.php`). No PHP class changes. All changes are Blade markup/class edits: grid breakpoints, filter-chip shape/colour, a photo-corner badge overlay, one bordered "info block" panel, and promoting the page's one primary CTA to `variant="primary"`. Every existing conditional branch (§6.2/§6.3/§6.4/§6.5 states, AC11's unconditional address, the AC12 capability-projection guards) is preserved verbatim — only its surrounding class recipe or position within its own section changes.

**Tech Stack:** Laravel Blade, Livewire 4, Tailwind CSS 4.1 (CSS-first, `tokens.css`/`app.css` `@utility` layer).

**Spec:** `.scratch/ffi-clone-whole-frontend/spec.md` (ticket 02: `.scratch/ffi-clone-whole-frontend/issues/02-cemetery-directory-restyle.md`)

**Reference source, read directly for this plan:** `/home/ubuntu/fundforindonesia.org` — `src/app/explore/all/page.tsx`, `src/components/campaign/CampaignGrid.tsx`, `src/components/campaign/CampaignCard.tsx`, `src/components/campaign/CampaignDetailView.tsx`, `src/components/search/CategoryFilter.tsx`.

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah di bawah keluar dengan status 0.

    ~/.claude/skills/specflow/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    ~/.claude/skills/specflow/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- **Cemetery directory (listing + detail).** Restyled to FFI's real filterable-grid pattern for the listing (card grid, category/city filter treatment) and FFI's real detail-page pattern for a single cemetery (hero image, structured info blocks, call-to-action), using only Makam's existing content and existing search/filter capability. No new backend query behavior is introduced. *(parent spec, Implementation Decisions)*
- **Photography rule, applied consistently.** The marketing/emotional register never shows closeup grave/tombstone imagery, but functional product photography of real cemetery grounds stays on cemetery listing/detail pages, where it serves a real purchasing decision. The existing informative `alt="Foto {cemetery name}"` attributes and the "Foto belum tersedia" placeholder states are kept exactly as they are — this page's photography is never made decorative. *(parent spec, Implementation Decisions)*
- **No backend or domain logic changes anywhere in this initiative.** Visual/presentation-layer only. *(parent spec, Implementation Decisions + Out of Scope)*
- **No filter behaviour, cemetery data, copy, or pricing changes as a side effect** — every `@if`/`@unless` branch, every Indonesian copy string, and every presenter call in both files stays byte-identical; only the classes/markup wrapping them change. *(ticket 02 acceptance criteria)*
- **`--radius-full`'s pill-button prohibition is lifted** (ADR-0043, `docs/superpowers/specs/2026-09-22-ffi-full-visual-clone-design.md` §2) — the filter-chip pill shape below relies on this explicit, already-decided lift; it is not a new design call made inside this plan.
- **The non-colour selection cue (WCAG 1.4.1, design-system.md §9.2 MUST-NOT) is never removed.** The active filter chip's `icon.check` tick and `aria-current` stay exactly as they are; only the chip's shape/colour recipe changes, reusing the already-contrast-verified `bg-primary-600`/`text-neutral-0` pair (design-system.md §3.3a, `verify-contrast.py`, 10.10:1) instead of inventing a new one.
- **No new interactive/visual risk that requires a new browser-level spec.** Every change in this plan is a static class/markup change (grid breakpoints, chip shape, an absolutely-positioned badge overlay with its own opaque background, a card wrapper, a button variant swap) — no new JS, no new scroll region, no new breakpoint-dependent collapse. Per the ticket's own allowance ("otherwise the route-test seam plus `ci/verify-docs.sh`'s own gates are sufficient"), the two route-test seams below plus `ci/verify-docs.sh` (GATE 1 contrast, GATE 11 z-index, GATE 12 focus) are the verification surface; no new Playwright spec is added.
- **Seams under test** (parent spec, Testing Decisions): `CemeteryDirectoryIndexRouteTest`, `CemeteryDetailRouteTest`.

---

### Task 1: Restyle the cemetery directory listing page (card grid + filter chips)

**Files:**
- Modify: `resources/views/livewire/public/directory/index.blade.php`
- Test: `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`

**Interfaces:**
- Consumes: nothing new — same `$cities`, `$types`, `$cards`, `$capabilitiesDegraded`, `$directoryUnavailable`, `$filtersActive`, `$q` properties `CemeteryDirectoryIndex` already exposes.
- Produces: nothing new consumed elsewhere — Task 2's file is independent.

**Concrete changes:**
1. Container gutter: change the page's inner wrapper from `mx-auto max-w-content px-4` to `mx-auto max-w-content px-4 md:px-6 lg:px-8`, matching the exact progressive-gutter recipe `home-page.blade.php`'s sections already use (e.g. its `verified-heading` section's `px-4 md:px-6 lg:px-8`).
2. Card grid breakpoints: replace **both** the loading-skeleton grid (`grid gap-4 md:grid-cols-2 xl:grid-cols-3`) and the real results `<ul aria-label="Daftar TPU dan TPS">` (`grid gap-4 md:grid-cols-2 xl:grid-cols-3`) with `grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3` — the literal class string already used for every cemetery-card grid on the FFI-restyled homepage (`home-page.blade.php`'s urgent-availability/newest/verified sections, and design-system.md §3.3b/§4.3's own documented card-grid recipe). Literal reuse, not a new recipe.
3. Filter chips (both the city `<nav aria-label="Filter kota">` and type `<nav aria-label="Filter jenis lokasi">` rows — four call sites: the "Semua Kota"/"Semua Jenis" buttons and the `@foreach` loop buttons in each): adopt FFI's real pill shape/colour (`CategoryFilter.tsx`: `rounded-full`, `px-4 py-1.5`, solid `bg-primary` + white text when active, `bg-gray-100` + secondary text when inactive) while keeping every existing accessibility guarantee:
   - Wrapper `<button>` class: `rounded-sm` → `rounded-full` (so the focus ring matches the new pill shape; everything else on the wrapper — `touch-target`, `wire:click`, `aria-current` — is unchanged).
   - Active chip's inner `<span>`: change from `inline-flex items-center gap-1 rounded-sm border border-primary-300 bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800` to `inline-flex items-center gap-1 rounded-full bg-primary-600 px-3 py-1 text-xs font-medium text-neutral-0` — the `icon.check` tick inside is unchanged (it inherits white via `stroke="currentColor"` automatically) and continues to render unconditionally when active.
   - Inactive chip: replace `<x-mk.badge intent="neutral">{{ label }}</x-mk.badge>` with a literal inactive-pill `<span>` (`inline-flex items-center gap-1 rounded-full bg-neutral-100 px-3 py-1 text-xs font-medium text-neutral-700`), matching the active span's literal-recipe convention this file already uses for the SAME documented reason (`<x-mk.badge>`'s `$attributes->merge()` only appends, never replaces, so a badge-based pill can't be built without leaving competing declarations — this file's own header comment already explains this for the active state; this extends the same reasoning to the inactive state so both states share one shape language).
   - No change to `wire:click`, `$set(...)`, `aria-current`, or which element wraps which — filter *behaviour* is untouched.
4. Everything else in this file (validation-error alert, capabilities-degraded alert, `$q` search acknowledgement, empty state, card contents — type/availability badges, name, address, facilities, price block, provider-unavailable alert, customer-service escape hatch) stays byte-identical.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah HTTP-level melalui `CemeteryDirectoryIndexRouteTest` (`$this->get(route('cemeteries.index'))` dan `Livewire::test(CemeteryDirectoryIndex::class)` untuk kasus yang butuh state filter), mengikuti pola ekstraksi substring yang sudah ada di file test ini (`test_bottom_nav_renders_with_no_active_tab`'s `strpos`/`substr` approach). Cakup SETIAP perilaku task ini MELALUI seam itu: grid class string baru benar-benar hadir di elemen `<ul aria-label="Daftar TPU dan TPS">` yang dirender, chip pill shape (`rounded-full`) hadir di kedua baris filter, chip aktif tetap membawa `icon.check`'s literal path data DAN `aria-current` (regresi terhadap `test_the_active_filter_chip_carries_a_non_colour_selection_cue` yang sudah ada), dan setiap AC3 field/harga/fasilitas/badge yang sudah diuji tetap lolos tanpa modifikasi assertion-nya. Nilai harapan test adalah literal yang diketahui (string class lengkap, path SVG lengkap), bukan dihitung ulang dari kode.

- [ ] Container gutter updated to `px-4 md:px-6 lg:px-8`.
- [ ] Both grids (skeleton + real `<ul>`) updated to `grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 lg:grid-cols-3`.
- [ ] All four filter-chip call sites (city "Semua"/loop, type "Semua"/loop) updated to the pill recipe above; `<x-mk.badge intent="neutral">` calls for the inactive chip states replaced with the literal inactive-pill span.
- [ ] New test: real assertion that `<ul aria-label="Daftar TPU dan TPS">` carries `grid-cols-1`, `sm:grid-cols-2`, `md:gap-6`, and `lg:grid-cols-3`.
- [ ] New test: real assertion that the filter-chip wrapper/span markup carries `rounded-full` in both filter `<nav>` regions.
- [ ] Existing `test_the_active_filter_chip_carries_a_non_colour_selection_cue` re-run and still green (no edit needed to the test itself — regression guard).
- [ ] `bash ci/verify-docs.sh` run from the repo root, all gates pass.
- [ ] Commit.

---

### Task 2: Restyle the cemetery detail page (hero photo, structured info block, primary CTA)

**Files:**
- Modify: `resources/views/livewire/public/directory/detail.blade.php`
- Test: `tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php`

**Interfaces:**
- Consumes: nothing new — same `$cemetery`, `$capabilities`, `$capabilitiesDegraded`, `$packages`, `$packagesUnavailable` properties `CemeteryDetail` already exposes, and Task 1's file is not touched here.
- Produces: nothing consumed elsewhere — last task in this plan.

**Concrete changes, matching FFI's real `CampaignDetailView.tsx` pattern (cover image with a corner badge overlay → title/info block → bordered content sections → one prominent CTA) while keeping this file's existing section ORDER and every existing conditional branch:**

1. **Hero photo + badge overlay.** Wrap the existing `@if ($photoUrl) <img ...> @else <div ...> @endif` block in a `<div class="relative overflow-hidden rounded-lg">` wrapper (both the real `<img>` and the "Foto belum tersedia" fallback `<div>` go inside it, unchanged otherwise — same `h-56 w-full object-cover md:h-72` sizing, same informative `alt="Foto {{ $cemetery->name }}"`). Add a badge overlay directly inside that wrapper, absolutely positioned over the photo's top-left corner: `<div class="absolute left-3 top-3 flex flex-wrap gap-2">` containing the SAME two badges currently rendered in `<header>` (`<x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>` and the availability badge `<x-mk.badge :intent="$availability['intent']" :icon="$availability['icon']">{{ $availability['label'] }}</x-mk.badge>`), matching FFI's real corner-badge-over-cover-image pattern (`CampaignDetailView.tsx`'s `isUrgent` badge). `<x-mk.badge>` already renders an opaque solid background per intent (`bg-[var(--mk-intent-*-bg)]`, already contrast-verified), so overlaying it on an arbitrary photo needs no new contrast work. Remove the now-duplicate badge row from `<header>`, which keeps only the `<h1>` name and the "Dikelola oleh ..." operator line.
2. **Structured info block — price/availability.** Wrap the existing "Kisaran biaya" `<section>`'s content (unchanged: the price figure, source/effective-date line, the `NEEDS_CONFIRMATION_LABEL` badge, the explanatory paragraph, and the "belum tersedia" fallback branch — every existing `@if`/`@else` branch stays) in `<x-mk.card emphasis="strong">`, giving it the same visually-prominent bordered-panel treatment FFI gives its amount/stats block, using this project's own existing card primitive rather than a new one. The `<h2 id="biaya-heading">` heading stays outside or as the card's `header` slot — implementer's choice, as long as `aria-labelledby="biaya-heading"` on the `<section>` and the heading's `id` are both preserved unchanged.
3. **Primary call-to-action.** The final "§6.10 Customer-service escape hatch" `<section>` (currently `<x-mk.button variant="secondary" :href="route('bantuan.index')">Hubungi Customer Service</x-mk.button>`) is wrapped in `<x-mk.card emphasis="base">` and its button's `variant` changes from `secondary` to `primary`. This is the page's first and only `primary`-variant button — design-system.md §2.3 DO ("Use `primary-600` for exactly one primary action per view") is currently unmet on this page (every button on it is `secondary`) and this change satisfies it, matching FFI's real pattern of one bold, unmistakable primary action on the detail page. No href, label, or destination changes — same `route('bantuan.index')`, same Indonesian copy.
4. Every other section (`Alamat` with its unconditional `<address>` and optional map button/embed, `Fasilitas`, `Ketersediaan paket dan kelas` with its §6.5/§6.2 branches and per-package rows, the `@unless ($capabilities->hasPublicPlotMap())` peta-blok section, `Langkah berikutnya`) stays exactly as it is today — same order, same copy, same conditionals, same AC12 projection guards.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah HTTP-level melalui `CemeteryDetailRouteTest` (`$this->get(route('cemeteries.show', [...]))`), mengikuti helper `exampleCemetery()` dan gaya assertion yang sudah ada di file ini. Cakup SETIAP perilaku task ini MELALUI seam itu: kedua badge (type + availability) masih benar-benar dirender pada halaman setelah dipindah ke overlay (regresi terhadap `test_the_detail_page_shows_every_ac3_field`), markup overlay (`absolute`, `left-3 top-3`) benar-benar hadir di sekitar elemen foto, wrapper `<x-mk.card>` (kelas `rounded-lg border`) benar-benar membungkus bagian "Kisaran biaya", tombol CTA akhir sekarang membawa kelas `bg-primary-600` (bukan lagi secondary-variant `border-primary-600`) sambil `href`-nya tetap `route('bantuan.index')` dan labelnya tetap "Hubungi Customer Service", DAN setiap test AC11/AC12/§6.2/§6.4/§6.5 yang sudah ada (alamat tak bersyarat, `registry_mode`/`certificate_mode` tidak pernah muncul, peta blok, paket) tetap lolos tanpa perubahan assertion-nya. Nilai harapan test adalah literal yang diketahui, bukan dihitung ulang dari kode.

- [ ] Photo wrapped in `relative overflow-hidden rounded-lg`; badge overlay added at `absolute left-3 top-3`; header's duplicate badge row removed (title + operator line only remain there).
- [ ] "Kisaran biaya" section's content wrapped in `<x-mk.card emphasis="strong">`; `aria-labelledby`/heading `id` preserved.
- [ ] Final CTA section wrapped in `<x-mk.card emphasis="base">`; its button's `variant` changed `secondary` → `primary`; href/label unchanged.
- [ ] New test: real assertion that the photo wrapper carries the overlay markup and both badges still render on the page.
- [ ] New test: real assertion that the price section's rendered HTML is wrapped by the card's `rounded-lg border` recipe.
- [ ] New test: real assertion that the final CTA button now carries `bg-primary-600` (not the old `border-primary-600` secondary recipe) while its `href` still resolves to `route('bantuan.index')`.
- [ ] All pre-existing tests in this file re-run and still green (no edits to their assertions) — regression guard for AC3/AC11/AC12/§6.2/§6.4/§6.5.
- [ ] `bash ci/verify-docs.sh` run from the repo root, all gates pass (GATE 1 contrast for the new solid-badge-on-photo overlay and the new primary button; GATE 2/3 no hardcoded/arbitrary values; GATE 12 focus).
- [ ] Both changed Blade files (this task's + Task 1's) compiled and syntax-checked via a real Blade-compile probe (this project's own established practice after the N-14 doc-comment-corruption incident — see this file's own header note) — no errors, no swallowed content.
- [ ] Commit.

---

**Self-review:**

- **Spec coverage:** every ticket 02 acceptance-criterion checkbox is addressed — card grid/spacing/typography (Task 1 §2, typography was already FFI-aligned and untouched), filter markup (Task 1 §3), detail-page hero/info-block/CTA (Task 2 §1–3), functional photography kept with informative alt (both tasks, explicit Global Constraint), no filter-behaviour/data/copy/pricing change (explicit Global Constraint + every task's "stays byte-identical" line), both route tests gain real new assertions (Task 1 + Task 2 checklists), the e2e question is explicitly answered rather than left silent (Global Constraints' "no new interactive/visual risk" line), and `ci/verify-docs.sh` is a checklist item in both tasks.
- **Placeholder scan:** no TBD/TODO. The one genuinely open judgment call (whether the `<h2 id="biaya-heading">` sits outside or inside `<x-mk.card>`'s `header` slot) is named explicitly as an implementer's choice with the invariant that must hold either way, not left ambiguous.
- **Regression risk:** deliberately conservative — no section reordering, no content/copy/data changes, no new JS, no fixed/sticky positioning (which would risk colliding with `<x-mk.bottom-nav>` on mobile — considered and explicitly rejected in favour of an in-flow card wrapper for the CTA). Every existing test in both files is expected to keep passing unmodified; only new tests are added.
- **Consistency with the rest of this initiative:** Task 1's grid recipe is a literal copy of the already-shipped, already-reviewed homepage cemetery-card grids (`home-page.blade.php`, Stage 3) rather than a new invention. The filter-chip pill shape relies on ADR-0043's already-decided pill-button lift, cited by name rather than re-argued here.
